<?php

namespace Fabrica\CollaborativeEditing;

if (!defined('WPINC')) { die(); }

require_once('singleton.php');
require_once('settings.php');

class Base extends Singleton {

	const DOMAIN = 'fabrica-collaborative-editing';

	private $postTypesSupported = array();

	public function init() {

		// Exit now for non-admin requests
		if (!is_admin()) { return; }

		// Heartbeat response is called via AJAX, so wouldn't get loaded via `load-post.php` hooks
		// Also, high priority because needs to be applied late to override/cancel edit lock data
		add_filter('heartbeat_received', array($this, 'filterHeartbeatResponse'), 999999, 3);

		// Exit now if AJAX request, to hook admin-only requests after
		if (wp_doing_ajax()) { return; }

		// Main hooks
		add_action('admin_init', array($this, 'cachePostTypesSupported'));
		add_action('load-edit.php', array($this, 'disablePostListLock'));
		add_action('load-post.php', array($this, 'disablePostEditLock'));
		add_action('edit_form_top', array($this, 'cacheLastRevisionData'));
		add_filter('wp_insert_post_data', array($this, 'checkEditConflicts'), 0, 2);
		add_action('edit_form_after_title', array($this, 'showResolutionHeader'));
		add_action('admin_enqueue_scripts', array($this, 'enqueueScripts'));
	}

	// Cache supported post types
	public function cachePostTypesSupported() {
		$settings = Settings::instance()->getSettings();
		$args = array('public' => true);
		$postTypes = get_post_types($args);
		foreach ($postTypes as $postType) {
			$fieldName = $postType . '_collaboration_enabled';
			if (isset($settings[$fieldName]) && $settings[$fieldName] == '1') {
				$this->postTypesSupported[] = $postType;
			}
		}
	}

	// Generates a transient ID from a post ID and user ID
	private function generateTransientID($postID, $userID) {
		if (!$postID || !$userID) { return false; }
		return 'fce_edit_conflict_' . $postID . '_' . $userID;
	}

	// Returns the latest published revision, excluding autosaves
	private function getLatestPublishedRevision($postID) {
		$args = array('posts_per_page', 1, 'suppress_filters' => false);
		add_filter('posts_where', array($this, 'filterOutAutosaves'), 10, 1);
		$revisions = wp_get_post_revisions($postID, $args);
		remove_filter('posts_where', array($this, 'filterOutAutosaves'));
		if (count($revisions) == 0) { return false; }
		return current($revisions);
	}

	// Adds the temporary WHERE clause needed to exclude autosave from the revisions list
	public function filterOutAutosaves($where) {
		global $wpdb;
		$where .= " AND " . $wpdb->prefix . "posts.post_name NOT LIKE '%-autosave-v1'";
		return $where;
	}

	// Completely disable Heartbeat on list page (to avoid 'X is editing' notifications)
	public function disablePostListLock() {
		if (!in_array(get_current_screen()->post_type, $this->postTypesSupported)) { return; } // Exit for unsupported post types
		wp_deregister_script('heartbeat');
		add_filter('wp_check_post_lock_window', '__return_false');
	}

	// Leave Heartbeat active on post edit (so we can push edits for instant resolution) but override single-user lock
	public function disablePostEditLock() {
		if (!in_array(get_current_screen()->post_type, $this->postTypesSupported)) { return; } // Exit for unsupported post types
		add_filter('show_post_locked_dialog', '__return_false');
		add_action('admin_enqueue_scripts', array($this, 'enqueueHeartbeatScript'), 20);
	}

	// Add last revision info as form data on post edit
	public function cacheLastRevisionData($post) {
		if (!$post) { return; } // Exit if some problem with the post
		if (!in_array($post->post_type, $this->postTypesSupported)) { return; } // Exit for unsupported post types
		$latestRevision = $this->getLatestPublishedRevision($post->ID);
		if (!$latestRevision) { return; }
		echo '<input type="hidden" id="fce_last_revision_id" name="_fce_last_revision_id" value="' . $latestRevision->ID . '">';

		// Also, set title to suggested version if transient saved – we do this here so it's already set before the title field is displayed
		$transientID = $this->generateTransientID($post->ID, get_current_user_id());
		$conflictsData = get_transient($transientID);
		if ($conflictsData === false) { return; }
		if (array_key_exists('post_title', $conflictsData)) { $post->post_title = $conflictsData['post_title']['content']; }
	}

	// Check for intermediate edits and show a diff for resolution
	public function checkEditConflicts($data, $rawData) {

		// Don't interfere with autosaves - shouldn't happen anyway since this hook is excluded from AJAX requests, but just in case
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return $data; }

		// Occasionally seems to get called with an ID of 0, escape early
		if ($rawData['ID'] == 0) { return $data; }

		// Only proceed if we've received the cached data about the previous revision (this will exclude unsupported post types)
		if (!array_key_exists('_fce_last_revision_id', $rawData)) { return $data; }

		// ... and if the current post actually has revisions
		$latestRevision = $this->getLatestPublishedRevision($rawData['ID']);
		if (!$latestRevision) { return $data; }

		// Define name of transient where we store the edit in case of a clash
		$transientID = $this->generateTransientID($rawData['ID'], get_current_user_id());

		// If no new revision, this is either a regular save or a successful manual merge, so delete any cached changes and leave
		if ($latestRevision->ID == $rawData['_fce_last_revision_id']) {
			delete_transient($transientID);
			return $data;
		}

		// If still here, a new revision has been published, so check conflicts
		// Retrieve the saved content of the post being edited, for the diff
		// [TODO] support certain custom fields as well?
		$savedPost = get_post($rawData['ID'], ARRAY_A);

		// Diff the title, body and other fields to see if there's a conflict...
		$conflictsData = array();

		// Post title
		if ($savedPost['post_title'] != wp_unslash($data['post_title'])) {
			$conflictsData['post_title'] = array(
				'type' => 'plain',
				'title' => __("Title", self::DOMAIN),
				'content' => wp_unslash($data['post_title'])
			);
			$data['post_title'] = $savedPost['post_title']; // Don't save a change yet
		}

		// Post body
		if ($savedPost['post_content'] != wp_unslash($data['post_content'])) {
			$conflictsData['post_content'] = array(
				'type' => 'wysiwyg',
				'title' => __("Content", self::DOMAIN),
				'content' => wp_unslash($data['post_content'])
			);
			$data['post_content'] = $savedPost['post_content']; // Don't save a change yet
		}

		// ACF fields selected for tracking
		$settings = Settings::instance()->getSettings();
		if (array_key_exists('conflict_fields_acf', $settings)) {
			foreach ($settings['conflict_fields_acf'] as $field) {

				// Make sure there is submitted data for this field
				$fieldObject = get_field_object($field, $savedPost['ID']);
				if (!$fieldObject) { continue; }
				if (!array_key_exists('acf', $rawData) || !array_key_exists($fieldObject['key'], $rawData['acf'])) { continue; }

				// If data for this field has been submitted, check it for changes
				$savedField = get_field($fieldObject['key'], $savedPost['ID'], false);
				if ($savedField != wp_unslash($rawData['acf'][$fieldObject['key']])) {
					if ($fieldObject['type'] == 'wysiwyg') {
						$type = 'wysiwyg';
					} else {
						$type = 'plain';
					}
					$conflictsData[$fieldObject['key']] = array(
						'type' => $type,
						'title' => __($fieldObject['label'], self::DOMAIN),
						'content' => wp_unslash($rawData['acf'][$fieldObject['key']])
					);
					$_POST['acf'][$fieldObject['key']] = $savedField; // Don't save a change yet
				}
			}
		}

		if (count($conflictsData) > 0) {

			// ... there is, so save the conflicted data in a transient based on the post ID and user ID
			set_transient($transientID, $conflictsData, WEEK_IN_SECONDS);
		}

		// Return data for saving
		return $data;
	}

	// Show header and steps for resolution
	public function showResolutionHeader($post) {
		if (!in_array(get_current_screen()->post_type, $this->postTypesSupported)) { return; } // Exit for unsupported post types

		$transientID = $this->generateTransientID($post->ID, get_current_user_id());
		$conflictsData = get_transient($transientID);

		// Leave if no transient (cached changes) set
		if ($conflictsData === false) { return; }

		// Restrict copying and pasting
		add_filter('tiny_mce_before_init', array($this, 'setInvalidTinyMCEElements'));

		// Display instructions and render diff
		?><h3 class="fce-resolution-header"><strong><?php _e("Your proposed changes clash with recent edits by other users.", self::DOMAIN); ?></strong><br><?php _e("Review the latest version, then copy and paste changes you would like to merge in your version.", self::DOMAIN); ?></h3><?php
		foreach ($conflictsData as $key => $field) {
			if ($key == 'post_title') {
				$savedValue = get_the_title($post->ID);

				// $post->post_title already reset above to user's suggestion above in cacheLastRevisionData()
			} else if ($key == 'post_content') {
				$savedValue = $post->post_content;

				// Show user's suggestion in editor
				$post->post_content = $field['content'];
			} else if (substr($key, 0, 5) == 'field') {
				$savedValue = get_field($key, $post->ID, false);

				// Show user's suggestion in editor
				add_filter('acf/prepare_field/key=' . $key, function($field) {
					global $post;
					$transientID = $this->generateTransientID($post->ID, get_current_user_id());
					$conflictsData = get_transient($transientID);
					if ($conflictsData === false) { return $field; }
					if (!array_key_exists($field['key'], $conflictsData)) { return $field; }
					$field['value'] = $conflictsData[$field['key']]['content'];
					return $field;
				});
			}
			if ($field['type'] == 'wysiwyg') {
				?><div class="fce-diff-pair">
					<div class="fce-diff-tabs">
						<div class="fce-diff-tab fce-diff-tab-visual fce-diff-tab--active"><?php _e("Visual", self::DOMAIN); ?></div>
						<div class="fce-diff-tab fce-diff-tab-text"><?php _e("Text", self::DOMAIN); ?></div>
					</div>
					<div class="fce-diff fce-diff-visual fce-diff--active">
						<h3 class="fce-resolution-subhead"><?php _e($field['title'], self::DOMAIN); ?></h3><?php
						echo $this->renderDiff($field['content'], $savedValue, true);
					?></div>
					<div class="fce-diff fce-diff-text">
						<h3 class="fce-resolution-subhead"><?php _e($field['title'], self::DOMAIN); ?></h3><?php
						echo $this->renderDiff($field['content'], $savedValue, false);
					?></div>
				</div><?php
			} else {
				?><div class="fce-diff fce-diff-text fce-diff--active">
					<h3 class="fce-resolution-subhead"><?php _e($field['title'], self::DOMAIN); ?></h3><?php
					echo $this->renderDiff($field['content'], $savedValue, false);
				?></div><?php
			}
		}
	}

	// Disallow pasting certain tags during merge resolution
	public function setInvalidTinyMCEElements($settings) {
		$settings['invalid_elements'] = 'table, ins, del';
		return $settings;
	}

	// Render the diff
	private function renderDiff($left, $right, $wysiwyg = false) {
		require_once('fce-wysiwyg-diff-renderer-table.php');

		$args = array(
			'title_left' => __("Your version", self::DOMAIN),
			'title_right' => __("Latest version", self::DOMAIN)
		);

		$left = normalize_whitespace($left);
		$right = normalize_whitespace($right);

		$left = explode("\n", $left);
		$right = explode("\n", $right);
		$diff = new \Text_Diff($left, $right);

		if ($wysiwyg) {
			$renderer = new \FCE_WYSIWYG_Diff_Renderer_Table($args);
		} else {
			$renderer = new \WP_Text_Diff_Renderer_Table($args);
		}
		$diff = $renderer->render($diff);

		$output = '<table class="diff" id="diff">';
		$output .= '<col class="content diffsplit left"><col class="content diffsplit middle"><col class="content diffsplit right">';
		if (array_key_exists('title', $args) || array_key_exists('title_left', $args) || array_key_exists('title_right', $args)) {
			$output .= '<thead>';
		}
		if (array_key_exists('title', $args)) {
			$output .= '<tr class="diff-title" id="diff"><th colspan="3">' . $args['title'] . '</th></tr>';
		}
		if ($args['title_left'] || $args['title_right'] ) {
			$output .= '<tr class="diff-sub-title"><th>' . $args['title_left'] . '</th><td></td><th>' . $args['title_right'] . '</th></tr>';
		}
		if (array_key_exists('title', $args) || array_key_exists('title_left', $args) || array_key_exists('title_right', $args)) {
			$output .= '</thead>';
		}
		$output .= '<tbody>' . $diff . '</tbody>';
		$output .= '</table>';
		return $output;
	}

	// Filter information sent back to browser in Heartbeat
	public function filterHeartbeatResponse($response, $data, $screenID) {

		// Only modify response when we've been passed our own data
		if (!isset($data['fce'])) {
			return $response;
		}

		// Add custom data
		// $response['data'] = $data;

		// Send the latest revision of current post which will be compared to the cached one to see if it's changed while editing
		$latestRevision = $this->getLatestPublishedRevision($data['fce']['post_id']);
		if ($latestRevision) {
			$response['fce'] = array(
				'last_revision_id' => $latestRevision->ID,
				'last_revision_content' => apply_filters('the_content', $latestRevision->post_content)
			);
		}

		// Override and thereby disable edit lock by eliminating the data sent
		unset($response['wp-refresh-post-lock']);

		// Send back to the browser
		return $response;
	}

	// Process information received from server in Heartbeat
	// Plus other interactions
	// [TODO] move to JS file?
	public function enqueueHeartbeatScript() {
		wp_enqueue_script('fce-heartbeat', plugin_dir_url(Plugin::MAIN_FILE) . 'js/heartbeat.js', array('jquery'));
	}

	// Set JSs and CSSs for Edit post and Browse revisions pages
	public function enqueueScripts($hookSuffix) {
		if ($hookSuffix == 'post.php') {
			wp_enqueue_style('fce-conflicts', plugin_dir_url(Plugin::MAIN_FILE) . 'css/conflicts.css');
			wp_enqueue_script('fce-conflicts', plugin_dir_url(Plugin::MAIN_FILE) . 'js/conflicts.js', array('jquery'));
		}
	}
}

Base::instance()->init();
