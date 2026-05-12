<?php
/**
 * Contributor program applications — stored in {@see Post_Extractor_DB::table_contributor_applications()}
 * (not WordPress posts). Constants remain for migration and backwards-compatible references.
 *
 * @package post-extractor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Extractor_Contributor_App {

	public const POST_TYPE = 'pe_contrib_app';

	public const META_FIRST = '_pe_app_first_name';
	public const META_SURNAME = '_pe_app_surname';
	public const META_EMAIL = '_pe_app_email';
	public const META_PHONE = '_pe_app_phone';
	public const META_LOCATION = '_pe_app_location';
	public const META_PUBLICATION = '_pe_app_publication';
	public const META_MODERATION = '_pe_app_moderation';
	public const META_SOURCE = '_pe_app_source';
	/** Full name (or combined first + last from legacy). */
	public const META_FULL_NAME = '_pe_app_name';
	/** Comma/JSON of Publication.name for display (all sites user asked for in this row). */
	public const META_PUBS_JSON   = '_pe_app_publications_json';
	public const META_ALL_SITES = '_pe_app_all_sites';
}
