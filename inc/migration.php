<?php
/**
 * Reversible multisite storage migration for Link Pages.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

define( 'EC_LINK_PAGE_MIGRATION_JOURNAL_OPTION', 'ec_link_page_storage_migration_journals' );
define( 'EC_LINK_PAGE_MIGRATION_SCHEMA_VERSION', 1 );
define( 'EC_LINK_PAGE_MIGRATION_JOURNAL_LIMIT', 25 );

/** Return the append-only migration participant registry. */
function ec_link_page_migration_participant_registry() {
	static $registry = null;
	if ( null === $registry ) {
		$registry = new class() {
			private $participants = array();

			public function register( $name, $callbacks, $priority ) {
				if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $name ) || ! is_int( $priority ) || ! is_array( $callbacks ) ) {
					return new WP_Error( 'invalid_link_page_migration_participant', 'The migration participant registration is invalid.' );
				}
				foreach ( array( 'plan', 'apply', 'validate', 'rollback' ) as $operation ) {
					if ( empty( $callbacks[ $operation ] ) || ! is_callable( $callbacks[ $operation ] ) ) {
						return new WP_Error( 'invalid_link_page_migration_participant', 'Every migration participant callback is required.' );
					}
				}
				if ( isset( $this->participants[ $name ] ) ) {
					return new WP_Error( 'duplicate_link_page_migration_participant', 'The migration participant is already registered.' );
				}
				$this->participants[ $name ] = compact( 'name', 'callbacks', 'priority' );
				return true;
			}

			public function snapshot() {
				$participants = array_values( $this->participants );
				usort(
					$participants,
					static function ( $a, $b ) {
						$order = $a['priority'] <=> $b['priority'];
						return 0 !== $order ? $order : strcmp( $a['name'], $b['name'] );
					}
				);
				return $participants;
			}
		};
	}
	return $registry;
}

/** Register one named owner of migration side effects. */
function ec_register_link_page_migration_participant( $name, $callbacks, $priority = 10 ) {
	return ec_link_page_migration_participant_registry()->register( $name, $callbacks, $priority );
}

/** Execute in one blog while exactly restoring a nested multisite context. */
function ec_link_page_migration_in_blog( $blog_id, $callback ) {
	$blog_id = absint( $blog_id );
	if ( ! $blog_id || ! get_site( $blog_id ) || ! is_callable( $callback ) ) {
		return new WP_Error( 'invalid_link_page_migration_blog', 'The migration blog context is invalid.' );
	}
	$switched = get_current_blog_id() !== $blog_id;
	if ( $switched && ! switch_to_blog( $blog_id ) ) {
		return new WP_Error( 'link_page_migration_context_failed', 'The migration blog context could not be entered.' );
	}
	try {
		return call_user_func( $callback );
	} catch ( Throwable $throwable ) {
		return new WP_Error( 'link_page_migration_exception', $throwable->getMessage() );
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/** Canonically normalize an array before hashing. */
function ec_link_page_migration_canonicalize( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
		ksort( $value, SORT_STRING );
	}
	foreach ( $value as $key => $item ) {
		$value[ $key ] = ec_link_page_migration_canonicalize( $item );
	}
	return $value;
}

/** Produce a stable SHA-256 fingerprint. */
function ec_link_page_migration_hash( $value ) {
	return hash( 'sha256', wp_json_encode( ec_link_page_migration_canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}

/** Return the exact persisted post fields used by core insertion. */
function ec_link_page_migration_post_fields( $post ) {
	$fields = array( 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type' );
	$data   = array( 'ID' => (int) $post->ID );
	foreach ( $fields as $field ) {
		$data[ $field ] = isset( $post->{$field} ) ? $post->{$field} : '';
	}
	return $data;
}

/** Read raw metadata rows without collapsing duplicates. */
function ec_link_page_migration_meta_rows( $post_ids ) {
	global $wpdb;
	if ( empty( $post_ids ) ) {
		return array();
	}
	$ids  = implode( ',', array_map( 'absint', $post_ids ) );
	$rows = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$ids}) ORDER BY post_id, meta_id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact duplicate rows require direct ordered reads.
	return is_array( $rows ) ? array_map(
		static function ( $row ) {
			return array(
				'post_id'        => (int) $row['post_id'],
				'meta_key'       => (string) $row['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Descriptor field, not a query argument.
				'meta_value'     => (string) $row['meta_value'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Descriptor field, not a query argument.
				'semantic_value' => maybe_unserialize( $row['meta_value'] ),
			);
		},
		$rows
	) : array();
}

/** Return every safe local file represented by attachment metadata. */
function ec_link_page_migration_attachment_files( $attachment_id ) {
	$relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
	$meta     = wp_get_attachment_metadata( $attachment_id );
	$backup   = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
	$paths    = array( $relative );
	$dir      = dirname( $relative );
	foreach ( is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array() as $size ) {
		if ( ! empty( $size['file'] ) ) {
			$paths[] = ( '.' === $dir ? '' : $dir . '/' ) . $size['file'];
		}
	}
	if ( ! empty( $meta['original_image'] ) ) {
		$paths[] = ( '.' === $dir ? '' : $dir . '/' ) . $meta['original_image'];
	}
	foreach ( is_array( $backup ) ? $backup : array() as $size ) {
		if ( ! empty( $size['file'] ) ) {
			$paths[] = ( '.' === $dir ? '' : $dir . '/' ) . $size['file'];
		}
	}
	$uploads = wp_upload_dir();
	$result  = array();
	foreach ( array_values( array_unique( array_filter( $paths ) ) ) as $path ) {
		$path = wp_normalize_path( $path );
		if ( '' === $path || 0 === strpos( $path, '/' ) || preg_match( '#(?:^|/)\.\.(?:/|$)#', $path ) ) {
			return new WP_Error(
				'unsafe_link_page_attachment_path',
				'An attachment path escapes the uploads directory.',
				array(
					'attachment_id' => $attachment_id,
					'path'          => $path,
				)
			);
		}
		$absolute = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . $path );
		$base     = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		if ( 0 !== strpos( $absolute, $base ) || ! is_file( $absolute ) || ! is_readable( $absolute ) ) {
			return new WP_Error(
				'missing_link_page_attachment_file',
				'A required attachment file is missing.',
				array(
					'attachment_id' => $attachment_id,
					'path'          => $path,
				)
			);
		}
		$result[] = array(
			'path'   => $path,
			'sha256' => hash_file( 'sha256', $absolute ),
			'bytes'  => filesize( $absolute ),
		);
	}
	usort(
		$result,
		static function ( $a, $b ) {
			return strcmp( $a['path'], $b['path'] );
		}
	);
	return $result;
}

/** Invoke one participant without leaking a switched blog context. */
function ec_link_page_migration_invoke_participant( $participant, $operation, $context ) {
	$blog_id = get_current_blog_id();
	$depth   = count( $GLOBALS['_wp_switched_stack'] ?? array() );
	try {
		$result = call_user_func( $participant['callbacks'][ $operation ], $context );
	} catch ( Throwable $throwable ) {
		$result = new WP_Error( 'link_page_migration_participant_exception', $throwable->getMessage(), array( 'participant' => $participant['name'] ) );
	} finally {
		$current_depth = count( $GLOBALS['_wp_switched_stack'] ?? array() );
		while ( $current_depth > $depth ) {
			restore_current_blog();
			$current_depth = count( $GLOBALS['_wp_switched_stack'] ?? array() );
		}
		if ( get_current_blog_id() !== $blog_id ) {
			$result = new WP_Error( 'link_page_migration_participant_context_corrupt', 'A migration participant corrupted the multisite context.', array( 'participant' => $participant['name'] ) );
		}
	}
	return $result;
}

/** Build the complete read-only migration inventory. */
function ec_plan_link_page_storage_migration( $source_blog_id, $destination_blog_id ) {
	global $wpdb;
	$source_blog_id      = absint( $source_blog_id );
	$destination_blog_id = absint( $destination_blog_id );
	if ( ! is_multisite() || ! $source_blog_id || ! $destination_blog_id || $source_blog_id === $destination_blog_id || ! get_site( $source_blog_id ) || ! get_site( $destination_blog_id ) ) {
		return new WP_Error( 'invalid_link_page_migration_sites', 'Distinct existing multisite source and destination blogs are required.' );
	}
	$plan   = array(
		'schema_version'      => EC_LINK_PAGE_MIGRATION_SCHEMA_VERSION,
		'mode'                => 'plan',
		'source_blog_id'      => $source_blog_id,
		'destination_blog_id' => $destination_blog_id,
		'posts'               => array(),
		'meta'                => array(),
		'attachments'         => array(),
		'participants'        => array(),
		'collisions'          => array(),
		'missing'             => array(),
		'unsupported'         => array(),
	);
	$source = ec_link_page_migration_in_blog(
		$source_blog_id,
		static function () use ( &$plan ) {
			global $wpdb;
			$posts = get_posts(
				array(
					'post_type'      => EC_LINK_PAGE_POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			$ids   = array_map(
				static function ( $post ) {
					return (int) $post->ID;
				},
				$posts
			);
			foreach ( $posts as $post ) {
				$plan['posts'][] = ec_link_page_migration_post_fields( $post );
			}
			if ( $ids ) {
				$list   = implode( ',', $ids );
				$checks = array(
					'comments'               => "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID IN ({$list})",
					'revisions'              => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent IN ({$list}) AND post_type = 'revision'",
					'taxonomy_relationships' => "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id IN ({$list})",
				);
				foreach ( $checks as $name => $sql ) {
					$count = (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- IDs are locally cast integers.
					if ( $count ) {
						$plan['unsupported'][] = array(
							'type'  => $name,
							'count' => $count,
						); }
				}
			}
			$plan['meta'] = ec_link_page_migration_meta_rows( $ids );
			$owners       = array();
			$slugs        = array();
			foreach ( $plan['posts'] as $post ) {
				$owner = ec_get_link_page_owner( $post['ID'] );
				if ( is_wp_error( $owner ) || ! $owner ) {
					$plan['unsupported'][] = array(
						'type'    => 'invalid_owner',
						'post_id' => $post['ID'],
					); } elseif ( isset( $owners[ $owner['reference'] ] ) ) {
					$plan['unsupported'][] = array(
						'type'    => 'duplicate_owner',
						'post_id' => $post['ID'],
					); } else {
						$owners[ $owner['reference'] ] = $post['ID']; }
					if ( isset( $slugs[ $post['post_name'] ] ) ) {
						$plan['unsupported'][] = array(
							'type'    => 'duplicate_slug',
							'post_id' => $post['ID'],
						); }
					$slugs[ $post['post_name'] ] = $post['ID'];
			}
			$context              = array(
				'source_blog_id'      => $plan['source_blog_id'],
				'destination_blog_id' => $plan['destination_blog_id'],
				'link_page_ids'       => $ids,
				'attachment_map'      => array(),
				'fingerprint'         => '',
				'journal_id'          => '',
				'journal_record'      => null,
			);
			$attachment_ids       = array();
			$attachment_semantics = array();
			foreach ( $plan['meta'] as $row ) {
				if ( in_array( $row['meta_key'], array( '_thumbnail_id', '_link_page_background_image_id', '_link_page_profile_image_id' ), true ) && absint( $row['semantic_value'] ) ) {
					$attachment_ids[] = absint( $row['semantic_value'] ); }
			}
			foreach ( $ids as $id ) {
				$children       = get_posts(
					array(
						'post_type'      => 'attachment',
						'post_status'    => 'any',
						'post_parent'    => $id,
						'posts_per_page' => -1,
						'fields'         => 'ids',
					)
				);
				$attachment_ids = array_merge( $attachment_ids, $children );
			}
			foreach ( ec_link_page_migration_participant_registry()->snapshot() as $participant ) {
				$result = ec_link_page_migration_invoke_participant( $participant, 'plan', $context );
				if ( is_wp_error( $result ) ) {
					$plan['unsupported'][] = array(
						'type'  => 'participant',
						'name'  => $participant['name'],
						'error' => $result->get_error_message(),
					);
					continue; }
				if ( ! is_array( $result ) || empty( $result['fingerprint'] ) ) {
					$plan['unsupported'][] = array(
						'type' => 'participant_plan',
						'name' => $participant['name'],
					);
					continue; }
				$result['attachment_ids'] = array_values( array_unique( array_map( 'absint', $result['attachment_ids'] ?? array() ) ) );
				$attachment_ids           = array_merge( $attachment_ids, $result['attachment_ids'] );
				foreach ( $result['attachment_semantics'] ?? array() as $semantic ) {
					$attachment_id = absint( $semantic['attachment_id'] ?? 0 );
					if ( ! $attachment_id || ! array_key_exists( 'destination_parent', $semantic ) || ( isset( $attachment_semantics[ $attachment_id ] ) && $attachment_semantics[ $attachment_id ] !== $semantic ) ) {
						$plan['unsupported'][] = array(
							'type'          => 'attachment_semantics',
							'name'          => $participant['name'],
							'attachment_id' => $attachment_id,
						);
						continue;
					}
					$attachment_semantics[ $attachment_id ] = $semantic;
				}
				$plan['participants'][ $participant['name'] ] = $result;
			}
			$attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) ) );
			sort( $attachment_ids, SORT_NUMERIC );
			foreach ( $attachment_ids as $id ) {
				$post = get_post( $id );
				if ( ! $post || 'attachment' !== $post->post_type ) {
					$plan['missing'][] = array(
						'type' => 'attachment',
						'id'   => $id,
					);
					continue; }
				$files = ec_link_page_migration_attachment_files( $id );
				if ( is_wp_error( $files ) ) {
					$plan['missing'][] = array_merge(
						array(
							'type' => 'file',
							'id'   => $id,
						),
						(array) $files->get_error_data()
					);
					continue; }
				$fields           = ec_link_page_migration_post_fields( $post );
				$default_parent   = in_array( (int) $fields['post_parent'], $ids, true ) ? (int) $fields['post_parent'] : 0;
				$requested_parent = isset( $attachment_semantics[ $id ] ) ? absint( $attachment_semantics[ $id ]['destination_parent'] ) : $default_parent;
				if ( ! in_array( $requested_parent, array_merge( array( 0 ), $ids ), true ) || ( in_array( (int) $fields['post_parent'], $ids, true ) && $requested_parent !== (int) $fields['post_parent'] ) ) {
					$plan['unsupported'][] = array(
						'type' => 'attachment_parent_semantics',
						'id'   => $id,
					);
					continue;
				}
				$fields['post_parent'] = $requested_parent;
				$plan['attachments'][] = array(
					'post'                  => $fields,
					'source_parent'         => (int) $post->post_parent,
					'destination_parent'    => (int) $fields['post_parent'],
					'participant_semantics' => $attachment_semantics[ $id ] ?? null,
					'files'                 => $files,
				);
			}
			$attachment_object_ids = array_column( array_column( $plan['attachments'], 'post' ), 'ID' );
			if ( $attachment_object_ids ) {
				$list   = implode( ',', array_map( 'absint', $attachment_object_ids ) );
				$checks = array(
					'attachment_comments'               => "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID IN ({$list})",
					'attachment_revisions'              => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent IN ({$list}) AND post_type = 'revision'",
					'attachment_taxonomy_relationships' => "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id IN ({$list})",
				);
				foreach ( $checks as $name => $sql ) {
					$count = (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- IDs are locally cast integers.
					if ( $count ) {
						$plan['unsupported'][] = array(
							'type'  => $name,
							'count' => $count,
						);
					}
				}
			}
			$plan['attachment_meta'] = ec_link_page_migration_meta_rows( array_column( array_column( $plan['attachments'], 'post' ), 'ID' ) );
			return true;
		}
	);
	if ( is_wp_error( $source ) ) {
		return $source; }
	$destination = ec_link_page_migration_in_blog(
		$destination_blog_id,
		static function () use ( &$plan ) {
			$uploads = wp_upload_dir();
			foreach ( array_merge( array_column( $plan['posts'], 'ID' ), array_column( array_column( $plan['attachments'], 'post' ), 'ID' ) ) as $id ) {
				if ( get_post( $id ) ) {
					$plan['collisions'][] = array(
						'type' => 'post_id',
						'id'   => $id,
					); }
			}
			foreach ( $plan['posts'] as $post ) {
				$slug_matches = get_posts(
					array(
						'post_type'      => EC_LINK_PAGE_POST_TYPE,
						'post_status'    => 'any',
						'name'           => $post['post_name'],
						'posts_per_page' => 1,
						'fields'         => 'ids',
					)
				);
				if ( $slug_matches ) {
					$plan['collisions'][] = array(
						'type' => 'slug',
						'slug' => $post['post_name'],
						'id'   => (int) $slug_matches[0],
					);
				}
			}
			foreach ( $plan['attachments'] as $attachment ) {
				foreach ( $attachment['files'] as $file ) {
					if ( file_exists( trailingslashit( $uploads['basedir'] ) . $file['path'] ) ) {
						$plan['collisions'][] = array(
							'type' => 'file',
							'id'   => $attachment['post']['ID'],
							'path' => $file['path'],
						); }
				}
			}
			return true;
		}
	);
	if ( is_wp_error( $destination ) ) {
		return $destination; }
	$source_material     = array_intersect_key( $plan, array_flip( array( 'schema_version', 'source_blog_id', 'destination_blog_id', 'posts', 'meta', 'attachments', 'attachment_meta', 'participants', 'unsupported', 'missing' ) ) );
	$plan['fingerprint'] = ec_link_page_migration_hash( $source_material );
	$plan['ready']       = empty( $plan['collisions'] ) && empty( $plan['missing'] ) && empty( $plan['unsupported'] );
	$plan['counts']      = array(
		'posts'        => count( $plan['posts'] ),
		'attachments'  => count( $plan['attachments'] ),
		'meta_rows'    => count( $plan['meta'] ) + count( $plan['attachment_meta'] ),
		'participants' => count( $plan['participants'] ),
	);
	return $plan;
}

/** Persist a bounded, non-autoloaded network journal collection. */
function ec_link_page_migration_store_journal( $journal ) {
	$journals                   = get_site_option( EC_LINK_PAGE_MIGRATION_JOURNAL_OPTION, array() );
	$journals                   = is_array( $journals ) ? $journals : array();
	$journals[ $journal['id'] ] = $journal;
	if ( count( $journals ) > EC_LINK_PAGE_MIGRATION_JOURNAL_LIMIT ) {
		$journals = array_slice( $journals, -EC_LINK_PAGE_MIGRATION_JOURNAL_LIMIT, null, true );
	}
	return update_network_option( null, EC_LINK_PAGE_MIGRATION_JOURNAL_OPTION, $journals );
}

/** Read one durable migration journal. */
function ec_link_page_migration_get_journal( $journal_id ) {
	$journals = get_site_option( EC_LINK_PAGE_MIGRATION_JOURNAL_OPTION, array() );
	return is_array( $journals ) && isset( $journals[ $journal_id ] ) ? $journals[ $journal_id ] : new WP_Error( 'link_page_migration_journal_not_found', 'The migration journal was not found.' );
}

/** Append intent, persist, execute, then mark applied. */
function ec_link_page_migration_mutate( &$journal, $entry, $callback ) {
	$entry['applied']     = false;
	$entry['sequence']    = count( $journal['entries'] ) + 1;
	$journal['entries'][] = $entry;
	if ( ! ec_link_page_migration_store_journal( $journal ) ) {
		return new WP_Error( 'link_page_migration_journal_write_failed', 'Mutation intent could not be journaled.' ); }
	$result = call_user_func( $callback );
	if ( is_wp_error( $result ) || false === $result ) {
		return is_wp_error( $result ) ? $result : new WP_Error( 'link_page_migration_mutation_failed', 'A journaled migration mutation failed.' ); }
	$journal['entries'][ count( $journal['entries'] ) - 1 ]['applied'] = true;
	if ( ! ec_link_page_migration_store_journal( $journal ) ) {
		return new WP_Error( 'link_page_migration_journal_write_failed', 'Applied mutation state could not be journaled.' ); }
	return $result;
}

/** Roll back core-owned journal entries in reverse dependency order. */
function ec_link_page_migration_compensate( &$journal ) {
	$journal['status'] = 'rolling_back';
	ec_link_page_migration_store_journal( $journal );
	$errors  = array();
	$context = ec_link_page_migration_journal_context( $journal );
	foreach ( array_reverse( ec_link_page_migration_participant_registry()->snapshot() ) as $participant ) {
		$result = ec_link_page_migration_invoke_participant( $participant, 'rollback', $context );
		if ( is_wp_error( $result ) ) {
			$errors[] = array(
				'participant' => $participant['name'],
				'error'       => $result->get_error_message(),
			); }
	}
	for ( $i = count( $journal['entries'] ) - 1; $i >= 0; --$i ) {
		$entry =& $journal['entries'][ $i ];
		if ( ! empty( $entry['rolled_back'] ) ) {
			continue; }
		if ( 'participant' === $entry['type'] ) {
			continue; }
		$result = ec_link_page_migration_in_blog(
			$journal['destination_blog_id'],
			static function () use ( $entry ) {
				if ( 'file' === $entry['type'] ) {
					if ( file_exists( $entry['path'] ) ) {
						wp_delete_file( $entry['path'] );
					}
					return ! file_exists( $entry['path'] ); }
				if ( 'meta' === $entry['type'] ) {
					return ! $entry['meta_id'] || delete_metadata_by_mid( 'post', $entry['meta_id'] ); }
				if ( 'post' === $entry['type'] ) {
					return ! get_post( $entry['post_id'] ) || (bool) wp_delete_post( $entry['post_id'], true ); }
				return true;
			}
		);
		if ( is_wp_error( $result ) || ! $result ) {
			$errors[] = array(
				'sequence' => $entry['sequence'],
				'error'    => is_wp_error( $result ) ? $result->get_error_message() : 'Rollback failed.',
			); } else {
			$entry['rolled_back'] = true;
			ec_link_page_migration_store_journal( $journal ); }
	}
	$journal['status']          = $errors ? 'failed' : 'rolled_back';
	$journal['rollback_errors'] = $errors;
	ec_link_page_migration_store_journal( $journal );
	return $errors ? new WP_Error( 'link_page_migration_rollback_incomplete', 'Rollback was incomplete.', $errors ) : $journal;
}

/** Build the callback-bearing participant context from a journal. */
function ec_link_page_migration_journal_context( &$journal ) {
	return array(
		'source_blog_id'      => $journal['source_blog_id'],
		'destination_blog_id' => $journal['destination_blog_id'],
		'link_page_ids'       => $journal['link_page_ids'],
		'attachment_map'      => $journal['attachment_map'],
		'fingerprint'         => $journal['fingerprint'],
		'journal_id'          => $journal['id'],
		'journal_entries'     => $journal['entries'],
		'participant_plans'   => $journal['participant_plans'] ?? array(),
		'journal_record'      => static function ( $entry, $callback ) use ( &$journal ) {
			$entry['type'] = 'participant';
			return ec_link_page_migration_mutate( $journal, $entry, $callback );
		},
	);
}

/**
 * Apply a previously fingerprinted plan without changing source or routing.
 *
 * @throws RuntimeException When a journaled mutation fails.
 */
function ec_apply_link_page_storage_migration( $source_blog_id, $destination_blog_id, $expected_fingerprint ) {
	$plan = ec_plan_link_page_storage_migration( $source_blog_id, $destination_blog_id );
	if ( is_wp_error( $plan ) ) {
		return $plan; }
	if ( ! hash_equals( (string) $expected_fingerprint, $plan['fingerprint'] ) ) {
		return new WP_Error( 'link_page_migration_source_drift', 'The source fingerprint changed; generate a fresh plan.' ); }
	if ( ! $plan['ready'] ) {
		return new WP_Error( 'link_page_migration_preflight_failed', 'Migration preflight found blockers.', $plan ); }
	$journal = array(
		'schema_version'      => EC_LINK_PAGE_MIGRATION_SCHEMA_VERSION,
		'id'                  => wp_generate_uuid4(),
		'status'              => 'applying',
		'created_at'          => gmdate( 'c' ),
		'source_blog_id'      => (int) $source_blog_id,
		'destination_blog_id' => (int) $destination_blog_id,
		'fingerprint'         => $plan['fingerprint'],
		'link_page_ids'       => array_column( $plan['posts'], 'ID' ),
		'attachment_map'      => array_combine( array_column( array_column( $plan['attachments'], 'post' ), 'ID' ), array_column( array_column( $plan['attachments'], 'post' ), 'ID' ) ),
		'entries'             => array(),
		'errors'              => array(),
		'participant_plans'   => $plan['participants'],
	);
	if ( false === $journal['attachment_map'] ) {
		$journal['attachment_map'] = array();
	}
	if ( ! ec_link_page_migration_store_journal( $journal ) ) {
		return new WP_Error( 'link_page_migration_journal_write_failed', 'The apply journal could not be created.' ); }
	try {
		$result = ec_link_page_migration_in_blog(
			$destination_blog_id,
			static function () use ( &$journal, $plan, $source_blog_id ) {
				$attachment_ids = array_column( array_column( $plan['attachments'], 'post' ), 'ID' );
				foreach ( array_merge( $plan['posts'], array_column( $plan['attachments'], 'post' ) ) as $post ) {
					$id   = (int) $post['ID'];
					$data = $post;
					unset( $data['ID'] );
					$data['import_id'] = $id;
					$inserted          = ec_link_page_migration_mutate(
						$journal,
						array(
							'type'    => 'post',
							'post_id' => $id,
						),
						static function () use ( $data, $id, $attachment_ids ) {
							$result = in_array( $id, $attachment_ids, true ) ? wp_insert_attachment( wp_slash( $data ), false, (int) $data['post_parent'], true ) : wp_insert_post( wp_slash( $data ), true );
							if ( is_wp_error( $result ) ) {
								return $result; }
							if ( (int) $result !== $id ) {
								return new WP_Error(
									'link_page_migration_id_mismatch',
									'Core did not preserve an imported object ID.',
									array(
										'expected' => $id,
										'actual'   => $result,
									)
								);
							}
							$actual   = ec_link_page_migration_post_fields( get_post( $id ) );
							$expected = $data;
							unset( $expected['import_id'] );
							$expected['ID'] = $id;
							return $actual === $expected ? $result : new WP_Error( 'link_page_migration_post_mismatch', 'Core did not preserve exact post fields.', array( 'post_id' => $id ) );
						}
					);
					if ( is_wp_error( $inserted ) ) {
						return $inserted; }
					foreach ( array_keys( get_post_meta( $id ) ) as $generated_meta_key ) {
						delete_post_meta( $id, $generated_meta_key );
					}
				}
				foreach ( array_merge( $plan['meta'], $plan['attachment_meta'] ) as $row ) {
					$meta_id = 0;
					$result  = ec_link_page_migration_mutate(
						$journal,
						array(
							'type'     => 'meta',
							'post_id'  => $row['post_id'],
							'meta_key' => $row['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Journal descriptor field.
							'meta_id'  => &$meta_id,
						),
						static function () use ( $row, &$meta_id ) {
							$meta_id = add_post_meta( $row['post_id'], $row['meta_key'], $row['semantic_value'], false ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact journaled metadata replay.
							return $meta_id ? $meta_id : false;
						}
					);
					$journal['entries'][ count( $journal['entries'] ) - 1 ]['meta_id'] = $meta_id;
					ec_link_page_migration_store_journal( $journal );
					if ( is_wp_error( $result ) ) {
						return $result; }
				}
				$destination_uploads = wp_upload_dir();
				$source_uploads      = ec_link_page_migration_in_blog(
					$source_blog_id,
					static function () {
						return wp_upload_dir();
					}
				);
				foreach ( $plan['attachments'] as $attachment ) {
					foreach ( $attachment['files'] as $file ) {
						$source      = trailingslashit( $source_uploads['basedir'] ) . $file['path'];
						$destination = trailingslashit( $destination_uploads['basedir'] ) . $file['path'];
						$result      = ec_link_page_migration_mutate(
							$journal,
							array(
								'type'          => 'file',
								'path'          => $destination,
								'relative_path' => $file['path'],
								'sha256'        => $file['sha256'],
							),
							static function () use ( $source, $destination, $file ) {
								if ( file_exists( $destination ) || ! wp_mkdir_p( dirname( $destination ) ) || ! copy( $source, $destination ) ) {
									return new WP_Error( 'link_page_migration_file_copy_failed', 'A destination media file could not be copied.' ); }
								return hash_equals( $file['sha256'], hash_file( 'sha256', $destination ) ) ? true : new WP_Error( 'link_page_migration_file_checksum_failed', 'A copied media checksum did not match.' );
							}
						);
						if ( is_wp_error( $result ) ) {
							return $result; }
					}
				}
				return true;
			}
		);
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() ); }
		$drift_check = ec_plan_link_page_storage_migration( $source_blog_id, $destination_blog_id );
		if ( is_wp_error( $drift_check ) || ! hash_equals( $journal['fingerprint'], $drift_check['fingerprint'] ) ) {
			throw new RuntimeException( 'The source changed during migration.' );
		}
		$context = ec_link_page_migration_journal_context( $journal );
		foreach ( ec_link_page_migration_participant_registry()->snapshot() as $participant ) {
			$result = ec_link_page_migration_invoke_participant( $participant, 'apply', $context );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( $result->get_error_message() ); }
		}
		$journal['status'] = 'applied';
		ec_link_page_migration_store_journal( $journal );
		return array(
			'mode'        => 'apply',
			'journal_id'  => $journal['id'],
			'status'      => 'applied',
			'fingerprint' => $journal['fingerprint'],
			'counts'      => $plan['counts'],
			'rollback'    => 'wp extrachill link-pages migrate-storage --rollback=' . $journal['id'],
		);
	} catch ( Throwable $throwable ) {
		$journal['status']   = 'failed';
		$journal['errors'][] = $throwable->getMessage();
		ec_link_page_migration_store_journal( $journal );
		$rollback = ec_link_page_migration_compensate( $journal );
		return new WP_Error(
			'link_page_migration_apply_failed',
			$throwable->getMessage(),
			array(
				'journal_id' => $journal['id'],
				'rollback'   => is_wp_error( $rollback ) ? $rollback->get_error_data() : 'completed',
			)
		);
	}
}

/** Validate all exact copied state represented by a journal. */
function ec_validate_link_page_storage_migration( $journal_id ) {
	$journal = ec_link_page_migration_get_journal( $journal_id );
	if ( is_wp_error( $journal ) ) {
		return $journal; }
	if ( 'applied' !== $journal['status'] ) {
		return new WP_Error( 'link_page_migration_not_applied', 'Only an applied journal can be validated.' ); }
	$plan = ec_plan_link_page_storage_migration( $journal['source_blog_id'], $journal['destination_blog_id'] );
	if ( is_wp_error( $plan ) ) {
		return $plan; }
	if ( ! hash_equals( $journal['fingerprint'], $plan['fingerprint'] ) ) {
		return new WP_Error( 'link_page_migration_source_drift', 'The source changed after apply.' ); }
	$expected_posts = array_merge( $plan['posts'], array_column( $plan['attachments'], 'post' ) );
	$destination    = ec_link_page_migration_in_blog(
		$journal['destination_blog_id'],
		static function () use ( $expected_posts, $plan ) {
			foreach ( $expected_posts as $expected ) {
				$post = get_post( $expected['ID'] );
				if ( ! $post || ec_link_page_migration_post_fields( $post ) !== $expected ) {
					return new WP_Error( 'link_page_migration_validation_failed', 'A destination post differs from its source descriptor.', array( 'post_id' => $expected['ID'] ) );
				}
			}
			$expected_meta = array_merge( $plan['meta'], $plan['attachment_meta'] );
			$actual_meta   = ec_link_page_migration_meta_rows( array_column( $expected_posts, 'ID' ) );
			if ( $expected_meta !== $actual_meta ) {
				return new WP_Error( 'link_page_migration_validation_failed', 'Destination metadata rows differ from source metadata rows.' );
			}
			$uploads = wp_upload_dir();
			foreach ( $plan['attachments'] as $attachment ) {
				foreach ( $attachment['files'] as $file ) {
					$path = trailingslashit( $uploads['basedir'] ) . $file['path'];
					if ( ! is_file( $path ) || ! hash_equals( $file['sha256'], hash_file( 'sha256', $path ) ) ) {
						return new WP_Error(
							'link_page_migration_validation_failed',
							'A destination attachment file is missing or has changed.',
							array(
								'attachment_id' => $attachment['post']['ID'],
								'path'          => $file['path'],
							)
						);
					}
				}
			}
			return true;
		}
	);
	if ( is_wp_error( $destination ) ) {
		return $destination;
	}
	$context = ec_link_page_migration_journal_context( $journal );
	foreach ( ec_link_page_migration_participant_registry()->snapshot() as $participant ) {
		$result = ec_link_page_migration_invoke_participant( $participant, 'validate', $context );
		if ( is_wp_error( $result ) ) {
			return $result; }
	}
	return array(
		'mode'        => 'validate',
		'journal_id'  => $journal_id,
		'status'      => 'valid',
		'fingerprint' => $journal['fingerprint'],
	);
}

/** Roll back only mutations owned by a durable journal. */
function ec_rollback_link_page_storage_migration( $journal_id ) {
	$journal = ec_link_page_migration_get_journal( $journal_id );
	if ( is_wp_error( $journal ) ) {
		return $journal; }
	if ( 'rolled_back' === $journal['status'] ) {
		return array(
			'mode'       => 'rollback',
			'journal_id' => $journal_id,
			'status'     => 'rolled_back',
			'idempotent' => true,
		); }
	$result = ec_link_page_migration_compensate( $journal );
	return is_wp_error( $result ) ? $result : array(
		'mode'       => 'rollback',
		'journal_id' => $journal_id,
		'status'     => 'rolled_back',
		'idempotent' => false,
	);
}

/** Ability callback for plan/apply/validate/rollback. */
function ec_migrate_link_page_storage_ability( $input ) {
	$mode = sanitize_key( $input['mode'] ?? 'plan' );
	if ( 'validate' === $mode ) {
		return ec_validate_link_page_storage_migration( $input['journal_id'] ?? '' ); }
	if ( 'rollback' === $mode ) {
		return ec_rollback_link_page_storage_migration( $input['journal_id'] ?? '' ); }
	if ( 'apply' === $mode ) {
		return ec_apply_link_page_storage_migration( $input['source_blog_id'] ?? 0, $input['destination_blog_id'] ?? 0, $input['expected_fingerprint'] ?? '' ); }
	return ec_plan_link_page_storage_migration( $input['source_blog_id'] ?? 0, $input['destination_blog_id'] ?? 0 );
}

/** Register the deliberately non-REST operator ability. */
function ec_register_link_page_storage_migration_ability() {
	wp_register_ability(
		'extrachill/migrate-link-page-storage',
		array(
			'label'               => 'Migrate Link Page storage',
			'description'         => 'Plan, apply, validate, or roll back a Link Page storage-site migration.',
			'category'            => 'site-management',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'mode'                 => array(
						'type'    => 'string',
						'enum'    => array( 'plan', 'apply', 'validate', 'rollback' ),
						'default' => 'plan',
					),
					'source_blog_id'       => array( 'type' => 'integer' ),
					'destination_blog_id'  => array( 'type' => 'integer' ),
					'expected_fingerprint' => array( 'type' => 'string' ),
					'journal_id'           => array( 'type' => 'string' ),
				),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'additionalProperties' => true,
			),
			'execute_callback'    => 'ec_migrate_link_page_storage_ability',
			'permission_callback' => static function () {
				return is_multisite() && current_user_can( 'manage_network_options' ); },
			'meta'                => array( 'show_in_rest' => false ),
		)
	);
}

add_action( 'wp_abilities_api_init', 'ec_register_link_page_storage_migration_ability' );
