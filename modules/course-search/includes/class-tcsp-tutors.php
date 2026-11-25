<?php
/**
 * Dedicated tutor directory/search page, sponsored tutor carousel and tutor cards.
 *
 * @package Tutor_Course_Search_Pro
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class TCSP_Tutors {
    const NONCE = 'tcsp_tutor_sponsorship_action';
    const PROMOTED_META = '_tcsp_promoted_tutor';
    const TIER_META = '_tcsp_tutor_promotion_tier';
    const START_META = '_tcsp_tutor_promotion_start';
    const END_META = '_tcsp_tutor_promotion_end';
    const USERS_PER_PAGE_MAX = 48;

    private $settings;
    private $last_total = 0;

    public function __construct( TCSP_Settings $settings ) { $this->settings = $settings; }

    public function register() {
        if ( ! $this->settings->get( 'enable_tutor_search' ) ) {
            add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
            return;
        }
        add_shortcode( 'tcsp_tutor_search', array( $this, 'shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 25 );
        add_filter( 'paginate_links', array( $this, 'pagination' ) );
        add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
        add_action( 'init', array( $this, 'maybe_create_page' ), 30 );
        add_filter( 'body_class', array( $this, 'body_class' ) );
        add_action( 'wp_ajax_tcsp_refresh_tutor_carousel', array( $this, 'ajax_refresh_carousel' ) );
        add_action( 'wp_ajax_nopriv_tcsp_refresh_tutor_carousel', array( $this, 'ajax_refresh_carousel' ) );
    }

    private function page_context() {
        if ( ! $this->settings->get( 'enable_tutor_search' ) ) return false;
        $slug = sanitize_title( $this->settings->get( 'tutor_page_slug' ) ?: 'tutors' );
        return is_page( $slug ) || ( isset( $_GET['tcsp_tutor_search'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['tcsp_tutor_search'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    public function body_class( $classes ) {
        if ( $this->page_context() ) {
            $classes[] = 'tcsp-tutor-directory';
        }
        return $classes;
    }

    public function enqueue() {
        if ( ! $this->page_context() ) return;
        wp_enqueue_style( 'tcsp-filters' );
        wp_enqueue_script( 'tcsp-filters', TCSP_URL . 'assets/js/tcsp-filters.js', array( 'jquery' ), TCSP_VERSION, true );
    }

    public function maybe_create_page() {
        if ( ! $this->settings->get( 'enable_tutor_search' ) || is_admin() ) return;
        $slug = sanitize_title( $this->settings->get( 'tutor_page_slug' ) ?: 'tutors' );
        if ( ! $slug || get_page_by_path( $slug ) ) return;
        wp_insert_post( array( 'post_title' => __( 'Tutors', 'tutor-learning-suite' ), 'post_name' => $slug, 'post_content' => '[tcsp_tutor_search]', 'post_status' => 'publish', 'post_type' => 'page' ) );
    }

    public function shortcode() {
        if ( ! $this->settings->get( 'enable_tutor_search' ) ) return '';
        ob_start();
        $this->render_page();
        return ob_get_clean();
    }

    private function request() {
        $g = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $arr = static function( $key ) use ( $g ) {
            if ( empty( $g[ $key ] ) ) return array();
            $raw = is_array( $g[ $key ] ) ? $g[ $key ] : array( $g[ $key ] );
            return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $raw ) ) ) );
        };
        $result = array(
            's' => isset( $g['tcsp_tutor_s'] ) && ! is_array( $g['tcsp_tutor_s'] ) ? sanitize_text_field( $g['tcsp_tutor_s'] ) : '',
            'sort' => isset( $g['tcsp_tutor_sort'] ) && ! is_array( $g['tcsp_tutor_sort'] ) ? sanitize_key( $g['tcsp_tutor_sort'] ) : 'relevance',
            'rating' => isset( $g['tcsp_tutor_rating'] ) && ! is_array( $g['tcsp_tutor_rating'] ) ? max( 0, min( 5, (float) $g['tcsp_tutor_rating'] ) ) : 0,
            'students' => isset( $g['tcsp_tutor_students'] ) && ! is_array( $g['tcsp_tutor_students'] ) ? max( 0, absint( $g['tcsp_tutor_students'] ) ) : 0,
            'courses' => isset( $g['tcsp_tutor_courses'] ) && ! is_array( $g['tcsp_tutor_courses'] ) ? max( 0, absint( $g['tcsp_tutor_courses'] ) ) : 0,
            'experience' => isset( $g['tcsp_tutor_experience'] ) && ! is_array( $g['tcsp_tutor_experience'] ) ? max( 0, absint( $g['tcsp_tutor_experience'] ) ) : 0,
            'verified' => isset( $g['tcsp_tutor_verified'] ) && ! is_array( $g['tcsp_tutor_verified'] ) ? sanitize_key( $g['tcsp_tutor_verified'] ) : '',
            'private' => isset( $g['tcsp_tutor_private'] ) && ! is_array( $g['tcsp_tutor_private'] ) ? sanitize_key( $g['tcsp_tutor_private'] ) : '',
            'languages' => $arr( 'tcsp_tutor_language' ),
            'types' => $arr( 'tcsp_tutor_type' ),
            'wilaya' => $arr( 'tcsp_tutor_wilaya' ),
            'municipality' => $arr( 'tcsp_tutor_municipality' ),
            'categories' => array_values( array_filter( array_map( 'absint', $arr( 'tcsp_tutor_category' ) ) ) ),
            'course_types' => $arr( 'tcsp_tutor_course_type' ),
        );
        // Match the course-search Wilaya/Municipality rule: Municipality is only
        // meaningful when exactly one Wilaya is selected. Ignore stale municipality
        // values whenever zero or multiple Wilayas are selected.
        if ( count( $result['wilaya'] ) !== 1 ) {
            $result['municipality'] = array();
        }
        return $result;
    }

    private function instructor_ids_query( $r, $ids_only = false ) {
        global $wpdb;
        $where = array( "u.ID > 0" ); $join = '';
        $hidden_tutors = method_exists( $this->settings, 'hidden_tutor_ids' ) ? $this->settings->hidden_tutor_ids() : array();
        if ( ! empty( $hidden_tutors ) ) {
            $hidden_csv = implode( ',', array_map( 'absint', $hidden_tutors ) );
            $where[] = "u.ID NOT IN ({$hidden_csv})";
        }
        $values = array();
        $role_like = '%' . $wpdb->esc_like( 'tutor_instructor' ) . '%';
        $where[] = $wpdb->prepare( "um.meta_value LIKE %s", $role_like );
        $join .= " INNER JOIN {$wpdb->usermeta} um ON um.user_id=u.ID AND um.meta_key=%s "; $values[] = $wpdb->prefix . 'capabilities';
        if ( $r['s'] !== '' ) {
            $like = '%' . $wpdb->esc_like( $r['s'] ) . '%';
            $description_key = 'description';
            $job_key = '_tutor_profile_job_title';
            $education_key = defined('Tlms_Ipe_Plugin::META_EDUCATION') ? Tlms_Ipe_Plugin::META_EDUCATION : '_tlms_ipe_education';
            $search_meta_keys = array( $description_key, $job_key, $education_key );
            $key_placeholders = implode(',', array_fill(0, count($search_meta_keys), '%s'));
            $params = array_merge($search_meta_keys, array($like));
            $where[] = $wpdb->prepare(
                '(u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_nicename LIKE %s OR u.user_email LIKE %s OR EXISTS (SELECT 1 FROM ' . $wpdb->usermeta . ' sm WHERE sm.user_id=u.ID AND sm.meta_key IN (' . $key_placeholders . ') AND sm.meta_value LIKE %s))',
                $like, $like, $like, $like, ...$params
            );
        }
        $meta_filters = array(
            'languages' => defined('Tlms_Ipe_Plugin::META_LANGUAGES') ? Tlms_Ipe_Plugin::META_LANGUAGES : '_tlms_ipe_spoken_languages',
            'types' => defined('Tlms_Ipe_Plugin::META_TUTORING_TYPES') ? Tlms_Ipe_Plugin::META_TUTORING_TYPES : '_tlms_ipe_tutoring_types',
            'wilaya' => defined('Tlms_Ipe_Plugin::META_WILAYA') ? Tlms_Ipe_Plugin::META_WILAYA : '_tlms_ipe_wilaya',
            'municipality' => defined('Tlms_Ipe_Plugin::META_COMMUNE') ? Tlms_Ipe_Plugin::META_COMMUNE : '_tlms_ipe_commune',
        );
        foreach ( $meta_filters as $filter => $key ) {
            if ( empty( $r[ $filter ] ) ) continue;
            $ors = array();
            foreach ( $r[ $filter ] as $v ) {
                $ors[] = $wpdb->prepare( 'm_' . $filter . '.meta_value LIKE %s', '%' . $wpdb->esc_like( $v ) . '%' );
            }
            $alias = 'm_' . $filter;
            $join .= $wpdb->prepare( " INNER JOIN {$wpdb->usermeta} {$alias} ON {$alias}.user_id=u.ID AND {$alias}.meta_key=%s ", $key );
            $where[] = '(' . implode( ' OR ', $ors ) . ')';
        }
        if ( 'verified' === $r['verified'] && $this->settings->get('enable_tutor_verified_filter') ) {
            $where[] = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->usermeta} vm WHERE vm.user_id=u.ID AND vm.meta_key=%s AND vm.meta_value='verified')", defined('Tlms_Ipe_Plugin::META_VERIFICATION_STATUS') ? Tlms_Ipe_Plugin::META_VERIFICATION_STATUS : '_tlms_ipe_verification_status' );
        }
        if ( 'yes' === $r['private'] && $this->settings->get('enable_tutor_private_sessions_filter') ) {
            $where[] = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->usermeta} pm WHERE pm.user_id=u.ID AND pm.meta_key=%s AND pm.meta_value='1')", defined('Tlms_Ipe_Plugin::META_ACCEPT_PRIVATE') ? Tlms_Ipe_Plugin::META_ACCEPT_PRIVATE : '_tlms_ipe_accept_private_sessions' );
        }
        return array( 'join' => $join, 'where' => $where, 'values' => $values );
    }

    private function stats( $user_ids ) {
        global $wpdb;
        $out=array(); $user_ids=array_values(array_filter(array_map('absint',$user_ids)));
        if(!$user_ids) return $out;
        $csv=implode(',', $user_ids);
        $meta_tier = $wpdb->prepare("SELECT user_id,meta_value FROM {$wpdb->usermeta} WHERE user_id IN ($csv) AND meta_key=%s", self::TIER_META);
        foreach((array)$wpdb->get_results($meta_tier) as $row) $out[(int)$row->user_id]['tier']=(string)$row->meta_value;
        $course_rows=$wpdb->get_results("SELECT p.post_author instructor_id, COUNT(DISTINCT p.ID) courses, COUNT(DISTINCT CASE WHEN e.ID IS NOT NULL THEN e.ID END) enrollments FROM {$wpdb->posts} p LEFT JOIN {$wpdb->posts} e ON e.post_parent=p.ID AND e.post_type='tutor_enrolled' AND e.post_status='completed' WHERE p.post_type='courses' AND p.post_status='publish' AND p.post_author IN ($csv) GROUP BY p.post_author");
        foreach((array)$course_rows as $row){$id=(int)$row->instructor_id;$out[$id]['courses']=(int)$row->courses;$out[$id]['students']=(int)$row->enrollments;}
        // Include Tutor multi-instructor course ownership too.
        $course_rows=$wpdb->get_results("SELECT um.user_id instructor_id, COUNT(DISTINCT p.ID) courses, COUNT(DISTINCT e.ID) enrollments FROM {$wpdb->usermeta} um INNER JOIN {$wpdb->posts} p ON p.ID=CAST(um.meta_value AS UNSIGNED) AND p.post_type='courses' AND p.post_status='publish' LEFT JOIN {$wpdb->posts} e ON e.post_parent=p.ID AND e.post_type='tutor_enrolled' AND e.post_status='completed' WHERE um.user_id IN ($csv) AND um.meta_key='_tutor_instructor_course_id' GROUP BY um.user_id");
        foreach((array)$course_rows as $row){$id=(int)$row->instructor_id;$out[$id]['courses']=max((int)($out[$id]['courses']??0),(int)$row->courses);$out[$id]['students']=(int)($out[$id]['students']??0)+(int)$row->enrollments;}
        $comments=$wpdb->get_results("SELECT p.post_author instructor_id, cm.meta_value rating FROM {$wpdb->comments} c INNER JOIN {$wpdb->commentmeta} cm ON cm.comment_id=c.comment_ID AND cm.meta_key='tutor_rating' INNER JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID WHERE c.comment_type='tutor_course_rating' AND c.comment_approved='approved' AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} hide WHERE hide.post_id=p.ID AND hide.meta_key='_tlms_ps_rating_course') AND (p.post_author IN ($csv) OR p.ID IN (SELECT CAST(meta_value AS UNSIGNED) FROM {$wpdb->usermeta} WHERE user_id IN ($csv) AND meta_key='_tutor_instructor_course_id'))");
        $ratings=array();
        foreach((array)$comments as $row){$ratings[] = array((int)$row->instructor_id, max(1,min(5,(float)$row->rating)));}
        foreach($ratings as [$id,$rating]){ $out[$id]['rating_sum']=($out[$id]['rating_sum']??0)+$rating; $out[$id]['reviews']=($out[$id]['reviews']??0)+1; }
        foreach($out as $id=>$x){$out[$id]['rating']=!empty($x['reviews'])?$x['rating_sum']/$x['reviews']:0;$out[$id]['courses']=(int)($x['courses']??0);$out[$id]['students']=(int)($x['students']??0);$out[$id]['reviews']=(int)($x['reviews']??0);}
        return $out;
    }

    private function tutor_course_type_map( $user_ids ) {
        global $wpdb;
        $user_ids = array_values( array_filter( array_map( 'absint', (array) $user_ids ) ) );
        $out = array();
        if ( ! $user_ids ) return $out;
        $csv = implode( ',', $user_ids );
        $meta_key = defined( 'TCF_META_TYPE' ) ? TCF_META_TYPE : '_tcf_course_type';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.post_author AS instructor_id, pm.meta_value AS course_type FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key=%s WHERE p.post_type='courses' AND p.post_status='publish' AND p.post_author IN ($csv)",
            $meta_key
        ) );
        foreach ( (array) $rows as $row ) {
            $id = (int) $row->instructor_id;
            $type = sanitize_key( (string) $row->course_type );
            if ( '' === $type ) $type = 'prerecorded';
            $out[ $id ][ $type ] = true;
        }
        // Include Tutor multi-instructor/course-assignment ownership too.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT um.user_id AS instructor_id, pm.meta_value AS course_type FROM {$wpdb->usermeta} um INNER JOIN {$wpdb->posts} p ON p.ID=CAST(um.meta_value AS UNSIGNED) AND p.post_type='courses' AND p.post_status='publish' LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key=%s WHERE um.user_id IN ($csv) AND um.meta_key='_tutor_instructor_course_id'",
            $meta_key
        ) );
        foreach ( (array) $rows as $row ) {
            $id = (int) $row->instructor_id;
            $type = sanitize_key( (string) $row->course_type );
            if ( '' === $type ) $type = 'prerecorded';
            $out[ $id ][ $type ] = true;
        }
        return $out;
    }

    private function meta_has_any($user_id,$key,$wanted){
        $wanted=array_values(array_unique(array_map('strval',(array)$wanted)));
        if(!$wanted)return true;
        $value=get_user_meta($user_id,$key,true);
        if(is_array($value))$values=$value;
        else{
            $value=trim((string)$value);
            if($value==='')return false;
            $decoded=json_decode($value,true);
            if(is_array($decoded))$values=$decoded;
            else $values=array($value);
        }
        $values=array_map('strval',(array)$values);
        foreach($wanted as $w)if(in_array($w,$values,true))return true;
        return false;
    }

    private function experience_years( $user_id ) {
        $key=defined('Tlms_Ipe_Plugin::META_EXPERIENCE')?Tlms_Ipe_Plugin::META_EXPERIENCE:'_tlms_ipe_experience';
        $rows=get_user_meta($user_id,$key,true);
        if(!is_array($rows)) return 0;
        $sum=0;
        foreach($rows as $row){ if(is_array($row)) $sum += max(0,absint($row['years']??0)); }
        return $sum;
    }
    private function education_rows( $user_id ) {
        $key=defined('Tlms_Ipe_Plugin::META_EDUCATION')?Tlms_Ipe_Plugin::META_EDUCATION:'_tlms_ipe_education';
        $rows=get_user_meta($user_id,$key,true);
        if(!is_array($rows)) return array();
        $out=array();
        foreach($rows as $row){
            if(!is_array($row)) continue;
            $name=sanitize_text_field(trim((string)($row['name']??'')));
            $place=sanitize_text_field(trim((string)($row['place']??'')));
            $date=sanitize_text_field(trim((string)($row['date']??'')));
            if($name!==''||$place!==''||$date!=='') $out[]=array('name'=>$name,'place'=>$place,'date'=>$date);
        }
        return $out;
    }
    private function tutor_job_title( $user_id ) {
        $value=get_user_meta($user_id,'_tutor_profile_job_title',true);
        return sanitize_text_field(trim(is_array($value)?implode(', ',array_map('strval',$value)):(string)$value));
    }
    private function tutor_bio( $user_id ) {
        $value=get_user_meta($user_id,'_tutor_profile_bio',true);
        if($value==='') { $u=get_userdata($user_id); $value=$u?$u->description:''; }
        return trim(wp_strip_all_tags((string)$value));
    }
    private function values( $user_id, $key ) { $v=get_user_meta($user_id,$key,true); if(is_array($v)) return array_values(array_filter(array_map('sanitize_text_field',$v))); $v=trim((string)$v); if('' === $v) return array(); $d=json_decode($v,true); return is_array($d)?array_values(array_filter(array_map('sanitize_text_field',$d))):($v!==''?array($v):array()); }
    private function wilaya_card_label( $user_id ) { $w=$this->values($user_id,defined('Tlms_Ipe_Plugin::META_WILAYA')?Tlms_Ipe_Plugin::META_WILAYA:'_tlms_ipe_wilaya'); if(!$w)return ''; $labels=[]; if(class_exists('Tlms_Ipe_Locations')){foreach($w as $x){$labels[]=Tlms_Ipe_Locations::get_wilaya_label($x)?:$x;}} else {$labels=$w;} $labels=array_values(array_unique(array_filter(array_map('strval',$labels)))); return implode(', ',$labels); }
    private function location_labels( $user_id ) { $w=$this->values($user_id,defined('Tlms_Ipe_Plugin::META_WILAYA')?Tlms_Ipe_Plugin::META_WILAYA:'_tlms_ipe_wilaya'); $c=$this->values($user_id,defined('Tlms_Ipe_Plugin::META_COMMUNE')?Tlms_Ipe_Plugin::META_COMMUNE:'_tlms_ipe_commune'); $parts=array(); if(class_exists('Tlms_Ipe_Locations')){foreach(array_slice($w,0,2) as $x){$label=Tlms_Ipe_Locations::get_wilaya_label($x);$parts[]=$label?:$x;} if(count($w)>2)$parts[]='+'.(count($w)-2).' '.__('wilayas','tutor-learning-suite');} else {$parts=array_slice($w,0,2);if(count($w)>2)$parts[]='+'.(count($w)-2).' '.__('wilayas','tutor-learning-suite');} if($c && class_exists('Tlms_Ipe_Locations')){ $map=Tlms_Ipe_Locations::commune_options_map();$cl=[];foreach($map as $opts){foreach($opts as $o){if(in_array((string)($o['value']??''),$c,true))$cl[]=$o['label']??$o['value'];}} if($cl){$shown=array_slice($cl,0,2);if(count($cl)>2)$shown[]='+'.(count($cl)-2).' '.__('more','tutor-learning-suite');$parts[] = implode(', ',$shown);} } return implode(' · ',$parts); }
    private function language_labels( $user_id ) { $vals=$this->values($user_id,defined('Tlms_Ipe_Plugin::META_LANGUAGES')?Tlms_Ipe_Plugin::META_LANGUAGES:'_tlms_ipe_spoken_languages'); if(class_exists('Tlms_Ipe_Plugin')){$choices=Tlms_Ipe_Plugin::language_choices();$out=[];foreach($vals as $v){if(isset($choices[$v]))$out[]=$choices[$v];}return $out;}return $vals; }
    private function type_labels( $user_id ) { $vals=$this->values($user_id,defined('Tlms_Ipe_Plugin::META_TUTORING_TYPES')?Tlms_Ipe_Plugin::META_TUTORING_TYPES:'_tlms_ipe_tutoring_types'); if(class_exists('Tlms_Ipe_Plugin')){$choices=Tlms_Ipe_Plugin::tutoring_type_choices();$out=[];foreach($vals as $v){if(isset($choices[$v]))$out[]=$choices[$v];}return $out;}return $vals; }
    private function category_names( $user_id ) { $key=defined('Tlms_Ipe_Plugin::META_TEACH_CATS')?Tlms_Ipe_Plugin::META_TEACH_CATS:'_tlms_ipe_teach_categories'; $ids=get_user_meta($user_id,$key,true); if(!is_array($ids))return []; $out=[]; foreach($ids as $id){$t=get_term(absint($id),'course-category'); if($t&&!is_wp_error($t))$out[]=$t->name;} return $out; }
    private function category_parent_links( $user_id ) {
        $key = defined('Tlms_Ipe_Plugin::META_TEACH_CATS') ? Tlms_Ipe_Plugin::META_TEACH_CATS : '_tlms_ipe_teach_categories';
        $raw = get_user_meta($user_id, $key, true);
        if (is_array($raw)) {
            $ids = $raw;
        } else {
            $raw = trim((string)$raw);
            $decoded = $raw !== '' ? json_decode($raw, true) : array();
            $ids = is_array($decoded) ? $decoded : ($raw !== '' ? preg_split('/\s*,\s*/', $raw) : array());
        }
        $ids = array_values(array_filter(array_unique(array_map('absint', (array)$ids))));
        if (!$ids) return array();
        $parents = array();
        foreach ($ids as $id) {
            $term = get_term($id, 'course-category');
            if (!$term || is_wp_error($term)) continue;
            $parent = (int)$term->parent ? get_term((int)$term->parent, 'course-category') : $term;
            if ($parent && !is_wp_error($parent)) $parents[(int)$parent->term_id] = $parent;
        }
        uasort($parents, function($a,$b){ return strcasecmp((string)$a->name,(string)$b->name); });
        return array_values($parents);
    }

    private function tutor_course_type_choices() {
        $choices = function_exists( 'tcf_course_types' ) ? tcf_course_types() : array(
            'prerecorded' => __( 'Prerecorded', 'tutor-learning-suite' ),
            'direct'      => __( 'Direct', 'tutor-learning-suite' ),
            'hybrid'      => __( 'Hybrid (Direct + Prerecorded)', 'tutor-learning-suite' ),
        );
        $out = array();
        foreach ( (array) $choices as $key => $label ) {
            if ( is_array( $label ) ) $label = $label['label'] ?? $key;
            $key = sanitize_key( (string) $key );
            if ( '' !== $key ) $out[ $key ] = wp_strip_all_tags( (string) $label );
        }
        return $out;
    }

    private function teaching_category_choices() {
        // WP_Term_Query expects 'orderby' to be a scalar string. Fetch by name,
        // then build the parent/child hierarchy in PHP so subcategories remain ordered
        // without passing an array into parse_orderby().
        $terms=get_terms(array('taxonomy'=>'course-category','hide_empty'=>false,'number'=>0,'orderby'=>'name','order'=>'ASC'));
        if(is_wp_error($terms)) return array();
        usort($terms,function($a,$b){
            $pa=(int)$a->parent; $pb=(int)$b->parent;
            if($pa===$pb) return strcasecmp((string)$a->name,(string)$b->name);
            return $pa<=>$pb;
        });
        $by_parent=array(); foreach($terms as $t){$by_parent[(int)$t->parent][]=$t;}
        $out=array();
        $walk=function($parent,$prefix='') use (&$walk,&$by_parent,&$out){ if(empty($by_parent[$parent])) return; foreach($by_parent[$parent] as $t){$out[(string)$t->term_id]=$prefix.$t->name; $walk((int)$t->term_id,$prefix.'— ');} };
        $walk(0);
        foreach($terms as $t){ if(!isset($out[(string)$t->term_id])) $out[(string)$t->term_id]=$t->name; }
        return $out;
    }

    private function all_instructors( $r ) {
        global $wpdb;
        $q = $this->instructor_ids_query( $r );
        $join = $q['join'];
        $where = $q['where'];
        $values = $q['values'];
        $filtered = array();
        $cursor = 0;
        $batch_size = 200;
        $lang_key = defined( 'Tlms_Ipe_Plugin::META_LANGUAGES' ) ? Tlms_Ipe_Plugin::META_LANGUAGES : '_tlms_ipe_spoken_languages';
        $type_key = defined( 'Tlms_Ipe_Plugin::META_TUTORING_TYPES' ) ? Tlms_Ipe_Plugin::META_TUTORING_TYPES : '_tlms_ipe_tutoring_types';
        $wilaya_key = defined( 'Tlms_Ipe_Plugin::META_WILAYA' ) ? Tlms_Ipe_Plugin::META_WILAYA : '_tlms_ipe_wilaya';
        $commune_key = defined( 'Tlms_Ipe_Plugin::META_COMMUNE' ) ? Tlms_Ipe_Plugin::META_COMMUNE : '_tlms_ipe_commune';
        $cat_key = defined( 'Tlms_Ipe_Plugin::META_TEACH_CATS' ) ? Tlms_Ipe_Plugin::META_TEACH_CATS : '_tlms_ipe_teach_categories';

        // Keyset batches keep the raw user rows and metadata working set bounded.
        // All supported filters and rankings are still applied across the full
        // candidate set so pagination totals and relevance sorting stay correct.
        do {
            $batch_where = $where;
            $batch_where[] = 'u.ID > %d';
            $batch_values = $values;
            $batch_values[] = $cursor;
            $batch_values[] = $batch_size;
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT u.ID, u.display_name, u.user_registered FROM {$wpdb->users} u {$join} WHERE " . implode( ' AND ', $batch_where ) . ' ORDER BY u.ID ASC LIMIT %d',
                $batch_values
            ) );
            if ( empty( $rows ) ) {
                break;
            }
            $ids = array();
            foreach ( $rows as $row ) {
                $ids[] = (int) $row->ID;
            }
            $cursor = max( $ids );
            if ( function_exists( 'update_meta_cache' ) ) {
                update_meta_cache( 'user', $ids );
            }
            $stats = $this->stats( $ids );
            $course_type_map = $this->tutor_course_type_map( $ids );

            foreach ( $rows as $row ) {
                $id = (int) $row->ID;
                $u = new stdClass();
                $u->ID = $id;
                $u->display_name = (string) $row->display_name;
                $u->user_registered = (string) $row->user_registered;
                $st = $stats[ $id ] ?? array( 'rating' => 0, 'reviews' => 0, 'courses' => 0, 'students' => 0 );
                $exp = $this->experience_years( $id );
                $u->tcsp_stats = $st;
                $u->tcsp_experience = $exp;
                $u->tcsp_location = $this->location_labels( $id );
                $u->tcsp_languages = $this->language_labels( $id );
                $u->tcsp_types = $this->type_labels( $id );
                $u->tcsp_categories = $this->category_names( $id );
                $u->tcsp_qualification = $this->education_rows( $id );
                $u->tcsp_job_title = $this->tutor_job_title( $id );
                $u->tcsp_bio = $this->tutor_bio( $id );
                $u->tcsp_course_types = array_keys( $course_type_map[ $id ] ?? array() );

                if ( $r['rating'] > 0 && $st['rating'] < $r['rating'] ) continue;
                if ( $r['students'] > 0 && $st['students'] < $r['students'] ) continue;
                if ( $r['courses'] > 0 && $st['courses'] < $r['courses'] ) continue;
                if ( $r['experience'] > 0 && $exp < $r['experience'] ) continue;
                if ( $r['languages'] && ! $this->meta_has_any( $id, $lang_key, $r['languages'] ) ) continue;
                if ( $r['types'] && ! $this->meta_has_any( $id, $type_key, $r['types'] ) ) continue;
                if ( $r['wilaya'] && ! $this->meta_has_any( $id, $wilaya_key, $r['wilaya'] ) ) continue;
                if ( $r['municipality'] && ! $this->meta_has_any( $id, $commune_key, $r['municipality'] ) ) continue;
                if ( $r['categories'] && ! $this->meta_has_any( $id, $cat_key, array_map( 'strval', $r['categories'] ) ) ) continue;
                if ( $r['course_types'] && $this->settings->get( 'enable_tutor_course_type_filter' ) ) {
                    $types_for_tutor = $course_type_map[ $id ] ?? array();
                    $matched = false;
                    foreach ( $r['course_types'] as $wanted_type ) {
                        if ( isset( $types_for_tutor[ $wanted_type ] ) ) { $matched = true; break; }
                    }
                    if ( ! $matched ) continue;
                }
                $filtered[] = $u;
            }
        } while ( count( $rows ) === $batch_size );

        $sort = $r['sort'];
        usort( $filtered, function ( $a, $b ) use ( $sort ) {
            $a_stats = $a->tcsp_stats;
            $b_stats = $b->tcsp_stats;
            switch ( $sort ) {
                case 'rating': $comparison = $b_stats['rating'] <=> $a_stats['rating']; break;
                case 'students': $comparison = $b_stats['students'] <=> $a_stats['students']; break;
                case 'courses': $comparison = $b_stats['courses'] <=> $a_stats['courses']; break;
                case 'experience': $comparison = $b->tcsp_experience <=> $a->tcsp_experience; break;
                case 'newest': $comparison = strcmp( $b->user_registered, $a->user_registered ); break;
                default:
                    $score_a = ( $a_stats['rating'] * 20 ) + log( 1 + $a_stats['students'] ) + ( $a_stats['courses'] * 2 ) + ( $a->tcsp_experience * .5 );
                    $score_b = ( $b_stats['rating'] * 20 ) + log( 1 + $b_stats['students'] ) + ( $b_stats['courses'] * 2 ) + ( $b->tcsp_experience * .5 );
                    $comparison = $score_b - $score_a;
            }
            return 0 !== $comparison ? $comparison : strcasecmp( $a->display_name, $b->display_name );
        } );
        $this->last_total = count( $filtered );
        return $filtered;
    }

    private function sponsored_tutors( $r, $users = null ) {
        $users = is_array($users) ? $users : $this->all_instructors($r);
        $tiers=$this->settings->promotion_tiers();$rows=[];foreach($users as $u){$id=(int)$u->ID;if('1'!==(string)get_user_meta($id,self::PROMOTED_META,true))continue;$start=(string)get_user_meta($id,self::START_META,true);$end=(string)get_user_meta($id,self::END_META,true);$today=current_time('Y-m-d');if($start&&$today<$start)continue;if($end&&$today>$end)continue;$tier=(string)get_user_meta($id,self::TIER_META,true);if(!$tier||!isset($tiers[$tier]))continue;$u->tcsp_tier=$tier;$u->tcsp_tier_label=$tiers[$tier]['label'];$u->tcsp_tier_weight=(int)$tiers[$tier]['weight'];$rows[]=$u;}usort($rows,function($a,$b){$c=$b->tcsp_tier_weight<=>$a->tcsp_tier_weight;return 0!==$c?$c:mt_rand(-1,1);});return array_slice($rows,0,max(3,(int)$this->settings->get('tutor_carousel_items'))); }

    private function render_page() {
        $r=$this->request();$per=max(6,min(self::USERS_PER_PAGE_MAX,(int)$this->settings->get('tutor_per_page')));$paged=max(1,absint(get_query_var('paged')));$all=$this->all_instructors($r);$total=count($all);$pages=max(1,(int)ceil($total/$per));if($paged>$pages)$paged=$pages;$normal=array_slice($all,($paged-1)*$per,$per);$sponsored=$this->settings->get('show_tutor_carousel')?$this->sponsored_tutors($r,$all):[];
        $communes=class_exists('Tlms_Ipe_Locations')?Tlms_Ipe_Locations::commune_options_map():[];$wilayas=class_exists('Tlms_Ipe_Locations')?Tlms_Ipe_Locations::wilaya_options():[];$langs=class_exists('Tlms_Ipe_Plugin')?Tlms_Ipe_Plugin::language_choices():[];$types=class_exists('Tlms_Ipe_Plugin')?Tlms_Ipe_Plugin::tutoring_type_choices():[];$categories=$this->teaching_category_choices();
        $has_active=($r['s']||$r['rating']||$r['students']||$r['courses']||$r['experience']||$r['verified']||$r['private']||$r['languages']||$r['types']||$r['wilaya']||$r['municipality']||$r['categories']||$r['course_types']);
        ?>
        <div class="tcsp-tutor-page"><div class="tcsp-tutor-layout"><aside class="tcsp-tutor-sidebar" id="tcsp-tutor-filter-drawer" aria-hidden="true"><div class="tcsp-tutor-drawer-head"><strong><?php esc_html_e('Filters','tutor-learning-suite');?></strong><button type="button" class="tcsp-tutor-drawer-close" aria-label="<?php esc_attr_e('Close filters','tutor-learning-suite');?>">×</button></div><form class="tcsp-searchbar tcsp-tutor-searchbar" method="get"><input type="hidden" name="tcsp_tutor_search" value="1"><div class="tcsp-searchbox"><input type="search" name="tcsp_tutor_s" value="<?php echo esc_attr($r['s']);?>" placeholder="<?php esc_attr_e('Search tutors…','tutor-learning-suite');?>" autocomplete="off"></div><select name="tcsp_tutor_sort" class="tcsp-select"><option value="relevance" <?php selected($r['sort'],'relevance');?>><?php esc_html_e('Relevance','tutor-learning-suite');?></option><option value="rating" <?php selected($r['sort'],'rating');?>><?php esc_html_e('Top rated','tutor-learning-suite');?></option><option value="students" <?php selected($r['sort'],'students');?>><?php esc_html_e('Most students','tutor-learning-suite');?></option><option value="courses" <?php selected($r['sort'],'courses');?>><?php esc_html_e('Most courses','tutor-learning-suite');?></option><option value="experience" <?php selected($r['sort'],'experience');?>><?php esc_html_e('Most experience','tutor-learning-suite');?></option><option value="newest" <?php selected($r['sort'],'newest');?>><?php esc_html_e('Newest tutors','tutor-learning-suite');?></option></select>
        <div class="tcsp-tutor-filter-groups">
        <?php
        if($this->settings->get('enable_tutor_teaching_category_filter')) $this->choice_field('tcsp_tutor_category','Teaching category',$categories,$r['categories']);
        if($this->settings->get('enable_tutor_course_type_filter')) $this->choice_field('tcsp_tutor_course_type','Course type',$this->tutor_course_type_choices(),$r['course_types']);
        if($this->settings->get('enable_tutor_language_filter')) $this->choice_field('tcsp_tutor_language','Languages',$langs,$r['languages']);
        if($this->settings->get('enable_tutor_tutoring_type_filter')) $this->choice_field('tcsp_tutor_type','Tutoring type',$types,$r['types']);
        $wilaya_options=array_reduce($wilayas,function($o,$v){$o[$v['value']]=$v['label'];return $o;},[]);
        if($this->settings->get('enable_tutor_wilaya_filter')) $this->choice_field('tcsp_tutor_wilaya','Wilaya',$wilaya_options,$r['wilaya']);
        $municipality_options=[];
        foreach($communes as $wcode=>$opts){foreach($opts as $o){$municipality_options[$o['value']]=array('label'=>$o['label'],'wilaya'=>(string)$wcode);}}
        if($this->settings->get('enable_tutor_municipality_filter')) $this->choice_field('tcsp_tutor_municipality','Municipality',$municipality_options,$r['municipality'], count($r['wilaya']) !== 1 ? 'tcsp-tutor-municipality-hidden' : '');
        if($this->settings->get('enable_tutor_rating_filter')): ?><select name="tcsp_tutor_rating" class="tcsp-select"><option value="0"><?php esc_html_e('Any rating','tutor-learning-suite');?></option><?php for($x=5;$x>=1;$x--):?><option value="<?php echo $x;?>" <?php selected($r['rating'],$x);?>><?php echo esc_html($x.'★ & up');?></option><?php endfor;?></select><?php endif;
        if($this->settings->get('enable_tutor_experience_filter')): ?><select name="tcsp_tutor_experience" class="tcsp-select"><option value="0"><?php esc_html_e('Any experience','tutor-learning-suite');?></option><?php foreach([1,3,5,10,15] as $x):?><option value="<?php echo $x;?>" <?php selected($r['experience'],$x);?>><?php echo esc_html($x.'+ years');?></option><?php endforeach;?></select><?php endif;
        if($this->settings->get('enable_tutor_verified_filter')): ?><select name="tcsp_tutor_verified" class="tcsp-select"><option value=""><?php esc_html_e('All tutors','tutor-learning-suite');?></option><option value="verified" <?php selected($r['verified'],'verified');?>><?php esc_html_e('Verified only','tutor-learning-suite');?></option></select><?php endif;
        if($this->settings->get('enable_tutor_private_sessions_filter')): ?><select name="tcsp_tutor_private" class="tcsp-select"><option value=""><?php esc_html_e('Private sessions: any','tutor-learning-suite');?></option><option value="yes" <?php selected($r['private'],'yes');?>><?php esc_html_e('Accept private sessions','tutor-learning-suite');?></option></select><?php endif;
        if($this->settings->get('enable_tutor_students_filter')): ?><select name="tcsp_tutor_students" class="tcsp-select"><option value="0"><?php esc_html_e('Any students','tutor-learning-suite');?></option><?php foreach([10,50,100,500,1000] as $x):?><option value="<?php echo $x;?>" <?php selected($r['students'],$x);?>><?php echo esc_html(number_format_i18n($x).'+ students');?></option><?php endforeach;?></select><?php endif;
        if($this->settings->get('enable_tutor_courses_filter')): ?><select name="tcsp_tutor_courses" class="tcsp-select"><option value="0"><?php esc_html_e('Any courses','tutor-learning-suite');?></option><?php foreach([1,3,5,10,20] as $x):?><option value="<?php echo $x;?>" <?php selected($r['courses'],$x);?>><?php echo esc_html($x.'+ courses');?></option><?php endforeach;?></select><?php endif;
        ?>
        </div>
        <button type="submit" class="tcsp-apply-btn"><?php esc_html_e('Apply','tutor-learning-suite');?></button><?php if($has_active):?><a class="tcsp-reset-btn" href="<?php echo esc_url(remove_query_arg(array_keys($_GET),home_url('/'.sanitize_title($this->settings->get('tutor_page_slug')).'/')));?>"><?php esc_html_e('Reset filters','tutor-learning-suite');?></a><?php endif;?></form></aside><div class="tcsp-tutor-drawer-backdrop" hidden></div><main class="tcsp-tutor-results"><div class="tcsp-tutor-results-toolbar"><button type="button" class="tcsp-tutor-filter-toggle" aria-controls="tcsp-tutor-filter-drawer" aria-expanded="false">☰ <?php esc_html_e('Filters','tutor-learning-suite');?></button><?php if($this->settings->get('show_tutor_result_count')):?><p class="tcsp-tutor-result-count"><?php if($r['s']!==''):?><?php echo esc_html(sprintf(_n('%1$s result for "%2$s"','%1$s results for "%2$s"',$total,'tutor-learning-suite'),number_format_i18n($total),$r['s']));?><?php else:?><?php echo esc_html(sprintf(_n('%s tutor found','%s tutors found',$total,'tutor-learning-suite'),number_format_i18n($total)));?><?php endif;?></p><?php endif;?></div>
        <?php if($sponsored):?><section class="tcsp-tutor-sponsored-carousel tcsp-sponsored-carousel"><div class="tcsp-sponsored-header"><div><span class="tcsp-sponsored-kicker">★ <?php esc_html_e('Sponsored tutors','tutor-learning-suite');?></span><h2><?php esc_html_e('Featured tutors','tutor-learning-suite');?></h2></div><div class="tcsp-carousel-controls"><button type="button" class="tcsp-carousel-prev" aria-label="Previous">‹</button><button type="button" class="tcsp-carousel-next" aria-label="Next">›</button></div></div><div class="tcsp-sponsored-track" tabindex="0"><?php foreach($sponsored as $u)$this->render_tutor_card($u,true);?></div></section><?php endif;?>
        <div class="tcsp-normal-results-heading"><?php esc_html_e('Tutors','tutor-learning-suite');?></div><div class="tcsp-tutor-grid"><?php foreach($normal as $u)$this->render_tutor_card($u,false);?></div>
        <?php if($pages>1):?><div class="tcsp-tutor-pagination"><?php echo wp_kses_post(paginate_links(array('base'=>str_replace('999999999','%#%',esc_url(get_pagenum_link(999999999))),'format'=>'?paged=%#%','current'=>$paged,'total'=>$pages,'type'=>'list')));?></div><?php endif;?></main></div></div><?php
    }

    private function choice_field($name,$label,$choices,$selected,$extra_class=''){
        if(!$choices)return;
        $id='tcsp-'.sanitize_title($name).'-'.wp_rand(1000,9999);
        $count=is_array($selected)?count($selected):0;
        ?>
        <div class="tcsp-multiselect tcsp-tutor-choice <?php echo esc_attr($extra_class);?>" data-tcsp-tutor-multi="1">
            <button type="button" class="tcsp-multiselect-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr($id);?>">
                <span><?php echo esc_html($label);?></span><?php if($count):?><span class="tcsp-multiselect-count"><?php echo (int)$count;?></span><?php endif;?>
            </button>
            <div id="<?php echo esc_attr($id);?>" class="tcsp-multiselect-panel tcsp-tutor-multi-panel" style="display:none">
                <?php foreach($choices as $k=>$v): $value=(string)(is_array($v)?($v['value']??$k):$k); $text=is_array($v)?($v['label']??$k):$v; $data_wilaya=is_array($v)?($v['wilaya']??''):''; ?>
                    <label class="tcsp-multiselect-option"><input type="checkbox" name="<?php echo esc_attr($name);?>[]" value="<?php echo esc_attr($value);?>" <?php if($data_wilaya!==''):?>data-wilaya="<?php echo esc_attr($data_wilaya);?>" <?php endif;?><?php checked(in_array($value,(array)$selected,true),true,false);?>> <span><?php echo esc_html($text);?></span></label>
                <?php endforeach;?>
            </div>
        </div>
        <?php
    }

    private function render_tutor_card($u,$sponsored=false){
        $id=(int)$u->ID; $st=is_array($u->tcsp_stats??null)?$u->tcsp_stats:array('rating'=>0,'reviews'=>0,'courses'=>0,'students'=>0); $url=$this->profile_url($id);
        $avatar=$this->settings->get('tutor_card_avatar')?get_avatar($id,96,'mystery_person',$u->display_name,array('class'=>'tcsp-tutor-avatar','loading'=>'lazy')):'';
        $verified='verified'===(string)get_user_meta($id,defined('Tlms_Ipe_Plugin::META_VERIFICATION_STATUS')?Tlms_Ipe_Plugin::META_VERIFICATION_STATUS:'_tlms_ipe_verification_status',true);
        $types=is_array($u->tcsp_types??null)?$u->tcsp_types:array(); $langs=is_array($u->tcsp_languages??null)?$u->tcsp_languages:array();
        $bio=(string)($u->tcsp_bio??''); $job=(string)($u->tcsp_job_title??''); $education=is_array($u->tcsp_qualification??null)?$u->tcsp_qualification:array();
        $parent_cats=$this->category_parent_links($id);
        $tier_class = $sponsored && !empty($u->tcsp_tier) ? sanitize_html_class((string)$u->tcsp_tier) : '';
        ?><article class="tcsp-tutor-card<?php echo $sponsored?' tcsp-tutor-card--sponsored':'';?><?php echo $tier_class ? ' tcsp-tutor-card--'.$tier_class : ''; ?>">
        <?php if($sponsored):?><div class="tcsp-tutor-sponsored-badge">★ <?php esc_html_e('Sponsored','tutor-learning-suite');?><?php if(!empty($u->tcsp_tier_label)):?> · <?php echo esc_html($u->tcsp_tier_label);?><?php endif;?></div><?php endif;?>
        <div class="tcsp-tutor-card-top"><a href="<?php echo esc_url($url);?>" class="tcsp-tutor-card-avatar"><?php echo $avatar?:'<span class="tcsp-tutor-avatar-placeholder">'.esc_html(strtoupper(substr($u->display_name,0,1))).'</span>';?></a><div class="tcsp-tutor-card-heading"><h3><a href="<?php echo esc_url($url);?>"><?php echo esc_html($u->display_name);?></a><?php if($verified&&$this->settings->get('tutor_card_verified')):?><span class="tcsp-verified-sticker" title="<?php esc_attr_e('Verified instructor','tutor-learning-suite');?>">✓ <span><?php esc_html_e('Verified instructor','tutor-learning-suite');?></span></span><?php endif;?></h3><?php if($this->settings->get('tutor_card_rating')):?><div class="tcsp-tutor-rating"><strong>★ <?php echo esc_html(number_format_i18n((float)($st['rating']??0),1));?></strong><?php if($this->settings->get('tutor_card_reviews')):?><span>(<?php echo esc_html(number_format_i18n((int)($st['reviews']??0)));?>)</span><?php endif;?></div><?php endif;?></div></div>
        <?php if($this->settings->get('tutor_card_bio')&&$bio):?><div class="tcsp-tutor-bio-wrap"><p class="tcsp-tutor-bio"><?php echo esc_html($bio);?><?php if(mb_strlen($bio)>180):?><a class="tcsp-tutor-show-more" href="<?php echo esc_url($url);?>"><?php esc_html_e('Show more','tutor-learning-suite');?></a><?php endif;?></p></div><?php endif;?>
        <?php if($this->settings->get('tutor_card_job_title')&&$job):?><p class="tcsp-tutor-line tcsp-tutor-job"><span aria-hidden="true">◉</span><span><strong><?php esc_html_e('Skill/Occupation','tutor-learning-suite');?>:</strong> <?php echo esc_html($job);?></span></p><?php endif;?>
        <?php if($this->settings->get('tutor_card_qualification')&&$education):?><div class="tcsp-tutor-education"><div class="tcsp-tutor-section-label"><?php esc_html_e('Education','tutor-learning-suite');?></div><?php foreach(array_slice($education,0,2) as $row):?><div class="tcsp-tutor-education-row"><strong><?php echo esc_html($row['name']);?></strong><?php if($row['place']):?><span><?php echo esc_html($row['place']);?></span><?php endif;?><?php if($row['date']):?><time><?php echo esc_html($row['date']);?></time><?php endif;?></div><?php endforeach;?><?php if(count($education)>2):?><span class="tcsp-tutor-more">+<?php echo (int)count($education)-2;?> <?php esc_html_e('more','tutor-learning-suite');?></span><?php endif;?></div><?php endif;?>
        <?php if($this->settings->get('tutor_card_categories') && $parent_cats):?><div class="tcsp-tutor-categories"><span class="tcsp-tutor-category-label"><?php esc_html_e('What I can teach','tutor-learning-suite');?>:</span><?php foreach(array_slice($parent_cats,0,4) as $cat):?><a href="<?php echo esc_url(add_query_arg(array('tcsp_tutor_search'=>'1','tcsp_tutor_category[]'=>(int)$cat->term_id),get_permalink()));?>"><?php echo esc_html($cat->name);?></a><?php endforeach;?><?php if(count($parent_cats)>4):?><span class="tcsp-tutor-category-more">+<?php echo (int)count($parent_cats)-4;?></span><?php endif;?></div><?php endif;?>
        <?php if($this->settings->get('tutor_card_experience')&& !empty($u->tcsp_experience)):?><p class="tcsp-tutor-line tcsp-tutor-experience"><span aria-hidden="true">◆</span><span><strong><?php echo esc_html(number_format_i18n((int)$u->tcsp_experience));?></strong> <?php esc_html_e('years of experience','tutor-learning-suite');?></span></p><?php endif;?>
        <?php if($this->settings->get('tutor_card_languages')&&$langs):?><div class="tcsp-tutor-tags"><?php foreach(array_slice($langs,0,3) as $x):?><span><?php echo esc_html($x);?></span><?php endforeach;?><?php if(count($langs)>3):?><span>+<?php echo (int)count($langs)-3;?></span><?php endif;?></div><?php endif;?>
        <?php if($this->settings->get('tutor_card_tutoring_types')&&$types):?><div class="tcsp-tutor-tags"><?php foreach(array_slice($types,0,2) as $x):?><span><?php echo esc_html($x);?></span><?php endforeach;?><?php if(count($types)>2):?><span>+<?php echo (int)count($types)-2;?></span><?php endif;?></div><?php endif;?>
        <div class="tcsp-tutor-stats"><?php if($this->settings->get('tutor_card_courses')):?><span><?php echo esc_html(number_format_i18n((int)($st['courses']??0)));?> <?php esc_html_e('courses','tutor-learning-suite');?></span><?php endif;?><?php if($this->settings->get('tutor_card_students')):?><span><?php echo esc_html(number_format_i18n((int)($st['students']??0)));?> <?php esc_html_e('students','tutor-learning-suite');?></span><?php endif;?></div></article><?php
    }

    private function profile_url($id){if(function_exists('tutor_utils')){try{$u=tutor_utils()->profile_url($id,true);if($u)return $u;}catch(Throwable $e){}}$u=get_userdata($id);return $u?add_query_arg('view','instructor',trailingslashit(home_url('profile/'.$u->user_nicename))):home_url('/');}

    public function pagination($links){if(!$this->page_context())return$links;$args=$this->request();foreach($args as $k=>$v){$key='tcsp_tutor_'.$k;if(is_array($v)){foreach($v as $x)$links=add_query_arg($key.'[]',$x,$links);}elseif($v!=='')$links=add_query_arg($key,$v,$links);}return$links;}
    public function ajax_refresh_carousel(){
        if ( function_exists( 'tls_rate_limited' ) && tls_rate_limited( 'tutor_carousel', 60, 60 ) ) { wp_send_json_error( array( 'message' => 'Too many requests. Please wait a moment.' ), 429 ); }check_ajax_referer('tcsp_tutor_carousel','nonce');if(!$this->page_context())wp_send_json_error();$this->render_page();wp_die();}

    public function handle_admin_actions(){if(!is_admin()||!current_user_can('manage_options')||empty($_POST['tcsp_tutor_action']))return;$nonce=isset($_POST['_wpnonce'])?sanitize_text_field(wp_unslash($_POST['_wpnonce'])):'';if(!wp_verify_nonce($nonce,self::NONCE))return;$action=sanitize_key(wp_unslash($_POST['tcsp_tutor_action']));$ids=isset($_POST['tutor_ids'])&&is_array($_POST['tutor_ids'])?array_values(array_filter(array_map('absint',$_POST['tutor_ids']))):[];$count=0;foreach($ids as $id){if(!get_userdata($id))continue;if($action==='remove'){foreach([self::PROMOTED_META,self::TIER_META,self::START_META,self::END_META] as $m)delete_user_meta($id,$m);$count++;continue;}if($action==='assign'){ $tier=isset($_POST['bulk_tier'])?sanitize_key(wp_unslash($_POST['bulk_tier'])):'';$tiers=$this->settings->promotion_tiers();if(!$tier||!isset($tiers[$tier]))continue;$schedule=isset($_POST['bulk_schedule'])?sanitize_key(wp_unslash($_POST['bulk_schedule'])):'no_expiry';$start='';$end='';if($schedule==='custom'){$start=$this->safe_date($_POST['bulk_start']??'');$end=$this->safe_date($_POST['bulk_end']??'');}elseif(ctype_digit($schedule)&&in_array((int)$schedule,[30,60,90,180,365],true)){$start=current_time('Y-m-d');$end=wp_date('Y-m-d',current_time('timestamp')+absint($schedule)*DAY_IN_SECONDS);}elseif($schedule==='immediate')$start=current_time('Y-m-d');update_user_meta($id,self::PROMOTED_META,1);update_user_meta($id,self::TIER_META,$tier);if($start)update_user_meta($id,self::START_META,$start);else delete_user_meta($id,self::START_META);if($end)update_user_meta($id,self::END_META,$end);else delete_user_meta($id,self::END_META);$count++;}}
        wp_safe_redirect(add_query_arg(array('page'=>'tcsp-tutor-sponsorships','updated'=>$count),admin_url('admin.php')));exit;}
    private function safe_date($x){$x=is_string($x)?sanitize_text_field(wp_unslash($x)):'';return preg_match('/^\d{4}-\d{2}-\d{2}$/',$x)?$x:'';}
    public function admin_page(){if(!current_user_can('manage_options'))return;$search=isset($_GET['s'])?sanitize_text_field(wp_unslash($_GET['s'])):'';$users=get_users(array('role'=>'tutor_instructor','search'=>$search?'*'.esc_attr($search).'*':'','search_columns'=>array('user_login','display_name','user_email'),'number'=>200,'orderby'=>'display_name','order'=>'ASC'));$tiers=$this->settings->promotion_tiers();?><div class="wrap"><h1><?php esc_html_e('Tutor Sponsorships','tutor-learning-suite');?></h1><p><?php esc_html_e('Assign the same sponsorship tiers used by sponsored courses to tutors. Higher-weight plans appear before lower-weight plans; tutors on the same plan rotate within that priority.','tutor-learning-suite');?></p><form method="get"><input type="hidden" name="page" value="tcsp-tutor-sponsorships"><input type="search" name="s" value="<?php echo esc_attr($search);?>" placeholder="<?php esc_attr_e('Search tutors…','tutor-learning-suite');?>"><button class="button"><?php esc_html_e('Search','tutor-learning-suite');?></button></form><form method="post" style="margin-top:15px"><?php wp_nonce_field(self::NONCE);?><input type="hidden" name="tcsp_tutor_action" value="assign"><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><select name="bulk_tier"><option value=""><?php esc_html_e('Select plan','tutor-learning-suite');?></option><?php foreach($tiers as $k=>$t):?><option value="<?php echo esc_attr($k);?>"><?php echo esc_html($t['label']);?></option><?php endforeach;?></select><select name="bulk_schedule"><option value="no_expiry"><?php esc_html_e('No expiry','tutor-learning-suite');?></option><option value="immediate"><?php esc_html_e('Start today','tutor-learning-suite');?></option><option value="30">30 days</option><option value="60">60 days</option><option value="90">90 days</option><option value="180">180 days</option><option value="365">365 days</option><option value="custom"><?php esc_html_e('Custom dates','tutor-learning-suite');?></option></select><input type="date" name="bulk_start"><input type="date" name="bulk_end"><button class="button button-primary"><?php esc_html_e('Assign to selected','tutor-learning-suite');?></button></div><table class="widefat striped" style="margin-top:15px"><thead><tr><th class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.tcsp-tutor-check').forEach(x=>x.checked=this.checked)"></th><th><?php esc_html_e('Tutor','tutor-learning-suite');?></th><th><?php esc_html_e('Current plan','tutor-learning-suite');?></th><th><?php esc_html_e('Dates','tutor-learning-suite');?></th></tr></thead><tbody><?php foreach($users as $u):$tier=(string)get_user_meta($u->ID,self::TIER_META,true);$st=(string)get_user_meta($u->ID,self::START_META,true);$en=(string)get_user_meta($u->ID,self::END_META,true);?><tr><th class="check-column"><input class="tcsp-tutor-check" type="checkbox" name="tutor_ids[]" value="<?php echo (int)$u->ID;?>"></th><td><strong><?php echo esc_html($u->display_name);?></strong><br><span class="description">@<?php echo esc_html($u->user_login);?></span></td><td><?php echo $tier&&isset($tiers[$tier])?esc_html($tiers[$tier]['label']):esc_html__('Not sponsored','tutor-learning-suite');?></td><td><?php echo esc_html(trim($st.' → '.$en,' →'));?></td></tr><?php endforeach;?></tbody></table></form></div><?php }
}
