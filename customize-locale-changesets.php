<?php
/**
 * Plugin Name: Customize Locale Changesets
 * Description: Proof of concept at leveraging changesets for creating language-specific versions of a site. Create a changeset and save as draft with a title of the language code. This changeset will then be served back depending on a lang query param or Accept-Langauge header. These changesets should never get published, as they should be long-lived branches of a site. Use with the Customize Posts plugin.
 * Version: 0.1.0-alpha
 * Author: Weston Ruter, XWP
 * Author URI: https://make.xwp.co
 * License: GPLv2+
 *
 * Copyright (c) 2017 XWP (https://make.xwp.co/)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2 or, at
 * your discretion, any later version, as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
 */

namespace Customize_Locale_Changesets;

add_filter( 'customize_changeset_branching', '__return_true' );

/**
 * Inject frontend changeset.
 */
function inject_frontend_changeset() {
	if ( is_admin() || isset( $_REQUEST['customize_changeset_uuid'] ) ) {
		return;
	}

	$language = '';
	if ( isset( $_REQUEST['lang'] ) ) {
		$language = $_REQUEST['lang'];
	} elseif ( isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
		$language = preg_replace( '/[,;].*$/', '', $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
	}
	if ( ! preg_match( '/^\w\w(-\w\w)?$/', $language ) ) {
		return;
	}
	$language = strtolower( $language );

	$changeset = array_shift( get_posts( array(
		'post_type' => 'customize_changeset',
		'title' => $language,
		'post_status' => 'draft',
		'posts_per_page' => 1,
	) ) );
	if ( empty( $changeset ) && strpos( $language, '-' ) ) {
		$changeset = array_shift( get_posts( array(
			'post_type' => 'customize_changeset',
			'title' => strtok( $language, '-' ),
			'post_status' => 'draft',
			'posts_per_page' => 1,
		) ) );
	}
	if ( ! $changeset || ! wp_is_uuid( $changeset->post_name ) ) {
		return;
	}

	$_REQUEST['customize_changeset_uuid'] = $changeset->post_name;
	$_GET['customize_changeset_uuid'] = $changeset->post_name;
	add_action( 'customize_preview_init', __NAMESPACE__ . '\locale_preview_init', 1000 );
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\dequeue_scripts', 1000 );
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\inject_frontend_changeset', 9 ); // Must happen before 10 when _wp_customize_include() fires.

/**
 * Set up frontend locale preview.
 *
 * WARNING: Robot-blocking headers and meta tag are still being added.
 *
 * @param \WP_Customize_Manager $wp_customize Manager.
 */
function locale_preview_init( \WP_Customize_Manager $wp_customize ) {
	remove_action( 'customize_preview_init', array( $wp_customize->selective_refresh, 'init_preview' ) );

	// Undo actions added in \WP_Customize_Manager::customize_preview_init().
	remove_filter( 'wp_headers', array( $wp_customize, 'filter_iframe_security_headers' ) );
	remove_filter( 'wp_redirect', array( $wp_customize, 'add_state_query_params' ) );
	remove_action( 'wp_head', array( $wp_customize, 'customize_preview_loading_style' ) );
	remove_action( 'wp_head', array( $wp_customize, 'remove_frameless_preview_messenger_channel' ) );
	remove_action( 'wp_footer', array( $wp_customize, 'customize_preview_settings' ), 20 );
	remove_filter( 'get_edit_post_link', '__return_empty_string' );

	if ( isset( $wp_customize->widgets ) ) {
		remove_action( 'wp_print_styles', array( $wp_customize->widgets, 'print_preview_css' ), 1 );
		remove_action( 'wp_footer',       array( $wp_customize->widgets, 'export_preview_data' ), 20 );
	}
	if ( isset( $wp_customize->nav_menus ) ) {
		remove_filter( 'wp_footer', array( $wp_customize->nav_menus, 'export_preview_data' ), 1 );
	}

	if ( function_exists( 'CustomizeSnapshots\get_plugin_instance' ) ) {
		remove_action( 'wp_enqueue_scripts', array( \CustomizeSnapshots\get_plugin_instance()->customize_snapshot_manager, 'enqueue_frontend_scripts' ) );
	}
}

/**
 * Dequeue scripts.
 *
 * @todo There should be a better way to do this.
 *
 * @global \WP_Customize_Manager $wp_customize Manager.
 */
function dequeue_scripts() {
	global $wp_customize;

	// Undo scripts and styles from being enqueued.
	wp_dequeue_script( 'customize-preview' );
	wp_dequeue_style( 'customize-preview' );

	remove_action( 'wp_footer', array( $wp_customize->selective_refresh, 'export_preview_data' ), 1000 );

	foreach ( wp_scripts()->queue as $handle ) {
		if ( ! isset( wp_scripts()->registered[ $handle ] ) ) {
			continue;
		}
		$script = wp_scripts()->registered[ $handle ];
		if ( in_array( 'customize-preview', $script->deps, true ) || in_array( 'customize-selective-refresh', $script->deps, true ) ) {
			wp_dequeue_script( $handle );
		}
	}
	foreach ( wp_styles()->queue as $handle ) {
		if ( ! isset( wp_styles()->registered[ $handle ] ) ) {
			continue;
		}
		$style = wp_styles()->registered[ $handle ];
		if ( in_array( 'customize-preview', $style->deps, true ) || in_array( 'customize-selective-refresh', $style->deps, true ) ) {
			wp_dequeue_style( $handle );
		}
	}
}
