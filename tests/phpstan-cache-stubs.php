<?php
/**
 * The Elementor, WP Rocket and Rocket.net CDN symbols the cache clears
 * after a plugin update call (P39b), declared for static analysis only.
 * Checked against Elementor 3.30 and CDN Cache Plugin 1.1.13.
 *
 * @package FoundryToolkit
 */

namespace Elementor {

	/** Elementor's files manager. */
	class Files_Manager {
		/** @return void */
		public function clear_cache() {
		}
	}

	/** Elementor's main plugin object. */
	class Plugin {
		/** @var Plugin */
		public static $instance;
		/** @var Files_Manager|null */
		public $files_manager;
	}

	/** Elementor's remote API. */
	class Api {
		/**
		 * @param bool $force_update Fetch now rather than use the transient.
		 * @return array<mixed>
		 */
		public static function get_library_data( $force_update = false ) {
			return array();
		}
	}
}

namespace {

	/**
	 * @param string $lang Language.
	 * @return bool
	 */
	function rocket_clean_domain( $lang = '' ) {
		return true;
	}

	/**
	 * @param string|string[] $extensions Extensions.
	 * @return void
	 */
	function rocket_clean_minify( $extensions = array( 'js', 'css' ) ) {
	}

	/** Rocket.net's CDN plugin hooks. */
	class CDN_Clear_Cache_Hooks {
		/** @return CDN_Clear_Cache_Hooks */
		public static function get_instance() {
			return new self();
		}

		/** @return mixed The decoded API answer, an object with success on success. */
		public static function purge_cache() {
			return null;
		}

		/** @return void */
		public function purge_wp_rocket_cache_clear() {
		}

		/** @return void */
		public function purge_cache_queue() {
		}
	}

	/** Rocket.net's circuit breaker for purge requests. */
	class CDN_Clear_Cache_Request_Guard {
		/** @return bool */
		public static function disabled() {
			return false;
		}
	}
}
