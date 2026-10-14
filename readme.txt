=== GoMigrate – Transfer All Posts, Pages & Custom Post Types ===
Contributors: devthrow
Tags: migration, migrate, export, import, custom post types
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Transfer posts, pages, custom post types, media, users, taxonomies, comments and selected plugin data between WordPress sites.

== Description ==

GoMigrate is a WordPress-to-WordPress migration utility for moving selected site data without requiring WP-CLI or third-party services.

It lets administrators select which content to export and import, including:

* Posts, pages and registered custom post types.
* Post metadata.
* Categories, tags and registered custom taxonomies.
* Taxonomy metadata and post-term relationships.
* Users and user metadata.
* Comments and comment metadata.
* Media attachment records and a media manifest.
* Navigation menus.
* WordPress options.
* Selected plugin or custom database tables.

The migration runs in small AJAX batches so large datasets can continue without one long-running browser request. A progress percentage is displayed during export and import.

URL replacement can update the old site URL inside post data, metadata, user metadata, options and selected table values. Serialized PHP arrays are handled before replacement and serialized again when saved.

No migration data is sent to an external service. Export packages are generated and processed on the WordPress site.

== Important ==

Always create a complete database and file backup before importing data.

The plugin does not package the physical files in wp-content/uploads. For large media libraries, copy the uploads directory separately and then import the attachment records.

Custom post types and taxonomies should already be registered on the destination site. Plugin-specific database tables should only be imported after the corresponding plugin is installed and has created its tables.

Export packages can contain private user information, site options and plugin data. Treat downloaded packages as sensitive files and delete them after migration.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New > Upload Plugin.
2. Activate GoMigrate.
3. Open Tools > GoMigrate.
4. Select the content and data types to export.
5. Run the export and download the generated JSON package.
6. Install and activate GoMigrate on the destination site.
7. Upload the JSON package under Tools > GoMigrate > Import.
8. Enter the old site URL when URL replacement is required.
9. Select the import mode and start the import.
10. Copy wp-content/uploads separately when migrating physical media files.

== Import Modes ==

= Update matching =

Existing posts are matched by post type and slug and are updated. Users are matched by email address.

= Skip matching =

Existing matching posts are retained. New records are imported where possible.

= Always create new =

Posts are imported as new records. User accounts are still matched by email to avoid duplicate accounts.

== Security ==

Only administrators with the manage_options capability can run exports and imports. AJAX actions use WordPress nonces and capability checks. Imported form values are sanitized and database output is escaped where displayed in the admin interface.

Plugin/custom table migration is an advanced feature and is disabled unless selected by the administrator.

== Privacy ==

GoMigrate does not transmit migration data to DevThrow or another external service. Data selected by the administrator is read from the local WordPress database and written to the local export package or destination database.

Export packages may include personal data such as user names, email addresses, comments and plugin-specific metadata. Administrators are responsible for handling and deleting export packages securely.

== Frequently Asked Questions ==

= Does it migrate custom post types? =

Yes. Registered post types are detected automatically and can be selected individually.

= Does it migrate Elementor data? =

Elementor content stored in post metadata is included when the relevant post type and post metadata are selected. Elementor should be installed on the destination site before importing.

= Does it migrate Awesome Support data? =

Awesome Support tickets stored as custom post types and their post metadata can be migrated when those post types are selected. Additional Awesome Support database tables can also be selected when they are visible in the plugin table list.

= Does it copy images and other uploads? =

The plugin migrates attachment records and creates a media manifest. Physical files in wp-content/uploads should be copied separately, especially for large media libraries.

= Can I migrate between different domains? =

Yes. Enter the old URL and destination URL during import. URL replacement is applied to supported content and serialized metadata.

= Is a backup recommended? =

Yes. Always back up the destination database and files before importing.

== Changelog ==

= 1.0.2 =
* Renamed the plugin to GoMigrate.
* Reworked JSON imports so the full package is not stored inside the AJAX job state.
* Large JSON packages are split into temporary sections and processed in batches.
* Added clearer upload and JSON validation errors.
* Improved AJAX error reporting.

= 1.0.1 =
* Fixed the export package download URL nonce handling.
* Added live activity preview logs during export and import.
* Added safer uploaded package validation.

= 1.0.0 =
* Initial release.
* Selectable posts, pages and custom post types.
* Taxonomy, relationship, user, comment and media migration.
* Navigation menu and WordPress options migration.
* Optional plugin/custom table migration.
* Batch processing with progress reporting.
* Serialized-data-aware URL replacement.
