<?php
/**
 * Front end display.
 *
 * Tutor LMS 4.x ships two course page layouts, both are supported:
 *
 *  1. Learning Area (4.x default) — templates/learning-area/subpages/course-info.php
 *     filter `tutor_learning_area_course_info_metadata`, items shaped
 *     array( 'icon' => <TUTOR\Icon name>, 'title' => string, 'content' => html ).
 *     Rendered as a two column table: icon + title | content.
 *  2. Legacy single course — templates/single/course/course-entry-box.php
 *     filter `tutor/course/single/sidebar/metadata`, items shaped
 *     array( 'icon_class' => string, 'label' => string, 'value' => html ).
 *     Rendered as a list: icon (label is only an aria attribute) | value.
 *
 * Rows shown:
 *
 *  - Prerecorded course  -> "Course Type: Prerecorded lessons" only.
 *  - Direct / Hybrid     -> "Course Type: Direct lessons" (or "Direct lessons
 *                           and prerecorded" for hybrid), then
 *                           "Study Type: Group study | 1v1 study", then
 *                           "Number: 10 live sessions for 1.5 hours per
 *                           session".
 *
 * @package TutorCourseFormat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class TCF_Frontend
 */
class TCF_Frontend {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'tutor_learning_area_course_info_metadata', array( $this, 'learning_area_rows' ), 20, 2 );
		add_filter( 'tutor/course/single/sidebar/metadata', array( $this, 'legacy_sidebar_rows' ), 20, 2 );
	}

	/**
	 * Build the rows once, shared by both layouts.
	 *
	 * Every row carries both an `icon` (TUTOR\Icon name, Learning Area layout)
	 * and an `icon_class` (legacy CSS class), because the two layouts use two
	 * completely different icon systems. Only classes that actually exist in
	 * Tutor's icon font / SVG set are used.
	 *
	 * @param int $course_id Course ID.
	 * @return array[] Each item has key, label, value, icon, icon_class.
	 */
	protected function get_rows( $course_id ) {
		$course_id = (int) $course_id;
		if ( ! tcf_should_display( $course_id ) ) {
			return array();
		}

		$data = tcf_get_course_format_data( $course_id );

		/*
		 * Row 1 - the course type, always shown.
		 *
		 *   prerecorded -> "Prerecorded lessons"
		 *   direct      -> "Direct lessons"
		 *   hybrid      -> "Direct lessons and prerecorded"
		 */
		$type_icons = array(
			'prerecorded' => array( 'monitor-play', 'tutor-icon-video-camera' ),
			'direct'      => array( 'video-camera', 'tutor-icon-meeting' ),
			'hybrid'      => array( 'lesson', 'tutor-icon-interactive' ),
		);
		$icons      = isset( $type_icons[ $data['type'] ] ) ? $type_icons[ $data['type'] ] : $type_icons['prerecorded'];

		$rows = array(
			array(
				'key'        => 'type',
				'label'      => __( 'Course Type', 'tutor-learning-suite' ),
				'value'      => tcf_get_type_display_label( $data['type'] ),
				'icon'       => $icons[0],
				'icon_class' => $icons[1],
			),
		);

		/*
		 * Prerecorded: no sessions to describe, so that single row is all.
		 */
		if ( ! $data['has_live'] ) {
			/**
			 * Filter the rows before they are rendered.
			 *
			 * @param array $rows      Each item: key, label, value, icon, icon_class.
			 * @param int   $course_id Course ID.
			 * @param array $data      Raw values from tcf_get_course_format_data().
			 */
			return apply_filters( 'tcf_display_rows', $rows, $course_id, $data );
		}

		/*
		 * Row 2 - how the live lessons are delivered.
		 */
		if ( '' !== (string) $data['format_label'] ) {
			$is_group = ( 'one_to_one' !== $data['format'] );

			$rows[] = array(
				'key'        => 'format',
				'label'      => __( 'Study Type', 'tutor-learning-suite' ),
				'value'      => $data['format_label'],
				'icon'       => $is_group ? 'user-circle' : 'user',
				'icon_class' => $is_group ? 'tutor-icon-user-group' : 'tutor-icon-user-line',
			);
		}

		/*
		 * Row 3 - how many live sessions, and how long each one is.
		 */
		$summary = $this->sessions_summary( $data );
		if ( '' !== $summary ) {
			$rows[] = array(
				'key'        => 'number',
				'label'      => __( 'Number', 'tutor-learning-suite' ),
				'value'      => $summary,
				'icon'       => 'calendar',
				'icon_class' => 'tutor-icon-calender',
			);
		}

		return apply_filters( 'tcf_display_rows', $rows, $course_id, $data );
	}

	/**
	 * "10 live sessions for 1.5 hours per session".
	 *
	 * Either half is dropped when it has not been filled in.
	 *
	 * @param array $data Raw values from tcf_get_course_format_data().
	 * @return string
	 */
	protected function sessions_summary( $data ) {
		$sessions = (int) $data['sessions'];
		$hours    = tcf_format_hours( $data['session_hours'] );

		$count = '';
		if ( $sessions > 0 ) {
			$count = sprintf(
				/* translators: %d: number of live sessions. */
				_n( '%d live session', '%d live sessions', $sessions, 'tutor-learning-suite' ),
				$sessions
			);
		}

		if ( '' === $hours ) {
			return $count;
		}

		$per_session = sprintf(
			1.0 === (float) $data['session_hours']
				/* translators: %s: session length in hours. */
				? __( '%s hour per session', 'tutor-learning-suite' )
				/* translators: %s: session length in hours. */
				: __( '%s hours per session', 'tutor-learning-suite' ),
			$hours
		);

		if ( '' === $count ) {
			return $per_session;
		}

		return sprintf(
			/* translators: 1: number of sessions, 2: length of each session. */
			__( '%1$s for %2$s', 'tutor-learning-suite' ),
			$count,
			$per_session
		);
	}

	/**
	 * Icon name (TUTOR\Icon) for a row, Learning Area layout.
	 *
	 * @param string $key  Row key.
	 * @param array  $row  Full row, including the default `icon`.
	 * @return string
	 */
	protected function learning_area_icon( $key, $row ) {
		$name = isset( $row['icon'] ) ? $row['icon'] : 'monitor-play';

		/**
		 * Filter the icon name used for a row in the Learning Area layout.
		 *
		 * @param string $name Any TUTOR\Icon constant value.
		 * @param string $key  Row key.
		 * @param array  $row  Full row.
		 */
		return apply_filters( 'tcf_learning_area_icon', $name, $key, $row );
	}

	/**
	 * Icon class for a row, legacy layout.
	 *
	 * @param string $key Row key.
	 * @param array  $row Full row, including the default `icon_class`.
	 * @return string
	 */
	protected function legacy_icon_class( $key, $row ) {
		$class = isset( $row['icon_class'] ) ? $row['icon_class'] : 'tutor-icon-mortarboard';

		/**
		 * Filter the icon class used for a row in the legacy layout.
		 *
		 * @param string $class Icon CSS class.
		 * @param string $key   Row key.
		 * @param array  $row   Full row.
		 */
		return apply_filters( 'tcf_legacy_icon_class', $class, $key, $row );
	}

	/**
	 * Learning Area course info table rows.
	 *
	 * @param array $metadata  Existing rows.
	 * @param int   $course_id Course ID.
	 * @return array
	 */
	public function learning_area_rows( $metadata, $course_id = 0 ) {
		if ( ! is_array( $metadata ) ) {
			return $metadata;
		}

		foreach ( $this->get_rows( $course_id ) as $row ) {
			if ( '' === (string) $row['value'] ) {
				continue;
			}

			$metadata[] = array(
				'icon'    => $this->learning_area_icon( $row['key'], $row ),
				'title'   => $row['label'],
				'content' => esc_html( $row['value'] ),
			);
		}

		return $metadata;
	}

	/**
	 * Legacy single course sidebar rows.
	 *
	 * @param array $metadata  Existing rows.
	 * @param int   $course_id Course ID.
	 * @return array
	 */
	public function legacy_sidebar_rows( $metadata, $course_id = 0 ) {
		if ( ! is_array( $metadata ) ) {
			return $metadata;
		}

		foreach ( $this->get_rows( $course_id ) as $row ) {
			if ( '' === (string) $row['value'] ) {
				continue;
			}

			$metadata[] = array(
				'icon_class' => $this->legacy_icon_class( $row['key'], $row ),
				'label'      => $row['label'],
				'value'      => esc_html( $row['value'] ),
			);
		}

		return $metadata;
	}
}
