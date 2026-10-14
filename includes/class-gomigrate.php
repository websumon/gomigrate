<?php
/**
 * Core migration functionality.
 *
 * @package GoMigrate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GoMigrate {
    const VERSION = '1.0.4';
    private static $instance;
    private $dir;


    public static function instance() {
        return self::$instance ?: (self::$instance = new self());
    }
    private function __construct() {
        $u = wp_upload_dir();
        $this->dir = trailingslashit($u['basedir']) . 'gomigrate';
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('wp_ajax_gomigrate_export', [$this, 'ajax_export']);
        add_action('wp_ajax_gomigrate_import', [$this, 'ajax_import']);
        add_action('admin_post_gomigrate_download', [$this, 'download']);
    }
    private function init_dir() { if (!is_dir($this->dir)) wp_mkdir_p($this->dir); }
    private function file($job) { $this->init_dir(); return $this->dir . '/' . sanitize_file_name($job) . '.json'; }
    private function package_dir($job) { $this->init_dir(); $dir = $this->dir . '/' . sanitize_file_name($job) . '-package'; if (!is_dir($dir)) wp_mkdir_p($dir); return $dir; }
    private function package_part_file($s, $key) {
        $dir = !empty($s['package_dir']) ? $s['package_dir'] : $this->package_dir($s['job']);
        return trailingslashit($dir) . sanitize_file_name($key) . '.json';
    }
    private function save($file, $data) { return false !== file_put_contents($file, wp_json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }
    private function load($file) { if (!is_file($file)) return []; $x=json_decode(file_get_contents($file),true); return is_array($x)?$x:[]; }
    private function load_package_part($s, $key) {
        $file = $this->package_part_file($s, $key);
        if (!is_file($file)) return [];
        $raw = file_get_contents($file);
        if ($raw === false || $raw === '') return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    private function cleanup_import_package($s) {
        if (empty($s['package_dir']) || !is_dir($s['package_dir'])) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        if ($wp_filesystem) {
            $wp_filesystem->delete($s['package_dir'], true);
        }
    }
    private function nonce() { return wp_create_nonce('gomigrate_nonce'); }
    private function post_types() {
        $out=[];
        foreach (get_post_types([], 'objects') as $k=>$o) {
            if (in_array($k,['revision','nav_menu_item','custom_css','customize_changeset','oembed_cache'],true)) continue;
            $out[$k] = $o->labels->name ?: $k;
        }
        return $out;
    }
    private function taxonomies() {
        $out=[];
        foreach (get_taxonomies([], 'objects') as $k=>$o) {
            if (in_array($k,['nav_menu','link_category'],true)) continue;
            $out[$k] = $o->labels->name ?: $k;
        }
        return $out;
    }
    private function tables() {
        global $wpdb;
        $core=[$wpdb->posts,$wpdb->postmeta,$wpdb->terms,$wpdb->term_taxonomy,$wpdb->term_relationships,$wpdb->termmeta,$wpdb->users,$wpdb->usermeta,$wpdb->comments,$wpdb->commentmeta,$wpdb->options];
        $out=[];
        foreach ((array)$wpdb->get_col('SHOW TABLES') as $table) {
            if (in_array($table,$core,true) || strpos($table,$wpdb->prefix)!==0) continue;
            $out[$table]=$table;
        }
        return $out;
    }
    private function arr($v) { return is_array($v) ? array_values(array_unique(array_filter(array_map('sanitize_key',$v)))) : []; }
    private function replace($v,$from,$to) {
        if (!$from) return $v;
        if (is_string($v)) return str_replace($from,$to,$v);
        if (is_array($v)) foreach($v as $k=>$x) $v[$k]=$this->replace($x,$from,$to);
        return $v;
    }
    private function add(&$p,$key,$rows) {
        if (!$rows) return;
        if (!isset($p[$key]) || !is_array($p[$key])) $p[$key]=[];
        foreach($rows as $r) $p[$key][]=$r;
    }
    private function add_log(&$s, $message, $type = 'info') {
        if (!isset($s['logs']) || !is_array($s['logs'])) {
            $s['logs'] = [];
        }
        $s['logs'][] = [
            'time' => current_time('H:i:s'),
            'type' => sanitize_key($type),
            'message' => wp_strip_all_tags((string) $message),
        ];
        if (count($s['logs']) > 40) {
            $s['logs'] = array_slice($s['logs'], -40);
        }
    }

    private function preview_rows($key, $rows) {
        $preview = [];
        foreach (array_slice((array) $rows, 0, 5) as $row) {
            switch ($key) {
                case 'posts':
                case 'media':
                    $title = isset($row['post_title']) && $row['post_title'] !== '' ? $row['post_title'] : ('ID ' . (int) ($row['ID'] ?? 0));
                    $preview[] = $title;
                    break;
                case 'users':
                    $preview[] = isset($row['user_login']) ? $row['user_login'] : ('ID ' . (int) ($row['ID'] ?? 0));
                    break;
                case 'terms':
                    $preview[] = isset($row['name']) ? $row['name'] : ('ID ' . (int) ($row['term_id'] ?? 0));
                    break;
                case 'comments':
                    $preview[] = isset($row['comment_author']) ? $row['comment_author'] : ('ID ' . (int) ($row['comment_ID'] ?? 0));
                    break;
                case 'postmeta':
                case 'termmeta':
                case 'usermeta':
                case 'commentmeta':
                    $preview[] = (string) ($row['meta_key'] ?? 'metadata');
                    break;
                case 'relationships':
                    $preview[] = 'Post #' . (int) ($row['object_id'] ?? 0) . ' → term #' . (int) ($row['term_id'] ?? 0);
                    break;
                default:
                    $preview[] = 'Record';
            }
        }
        return $preview;
    }

    private function log_export_batch(&$s, $key, $rows) {
        $count = count((array) $rows);
        if (!$count) {
            $this->add_log($s, ucfirst($key) . ': no more records found.', 'info');
            return;
        }
        $processed = (int) ($s['cursor'][$key] ?? $count);
        $items = $this->preview_rows($key, $rows);
        $suffix = $items ? ' — ' . implode(', ', array_map('sanitize_text_field', $items)) : '';
        $this->add_log($s, sprintf('%s: %d records processed (%d total)%s', ucfirst($key), $count, $processed, $suffix), 'success');
    }

    private function log_import_batch(&$s, $key, $rows) {
        $count = count((array) $rows);
        if (!$count) {
            $this->add_log($s, ucfirst($key) . ': no more records found.', 'info');
            return;
        }
        $processed = (int) ($s['cursor'][$key] ?? $count);
        $items = $this->preview_rows($key, $rows);
        $suffix = $items ? ' — ' . implode(', ', array_map('sanitize_text_field', $items)) : '';
        $this->add_log($s, sprintf('%s: %d records imported (%d total)%s', ucfirst($key), $count, $processed, $suffix), 'success');
    }

    private function download_url($job) {
        return add_query_arg(
            [
                'action' => 'gomigrate_download',
                'job' => sanitize_file_name($job),
                '_wpnonce' => wp_create_nonce('gomigrate_download'),
            ],
            admin_url('admin-post.php')
        );
    }

    private function new_export($r) {
        global $wpdb;
        $job='export-'.gmdate('Ymd-His').'-'.wp_generate_password(6,false,false);
        $types=$this->arr($r['post_types']??[]); $tax=$this->arr($r['taxonomies']??[]); $tables=$this->arr($r['plugin_tables']??[]);
        if (!empty($r['media'])) $types[]='attachment'; $types=array_values(array_unique($types));
        $state=[
            'job'=>$job,'type'=>'export','site_url'=>home_url('/'),'created'=>time(),
            'post_types'=>$types,'taxonomies'=>$tax,'plugin_tables'=>$tables,
            'opt'=>[
                'posts'=>!empty($r['posts']),'postmeta'=>!empty($r['postmeta']),'terms'=>!empty($r['terms']),
                'users'=>!empty($r['users']),'usermeta'=>!empty($r['usermeta']),'comments'=>!empty($r['comments']),
                'commentmeta'=>!empty($r['commentmeta']),'media'=>!empty($r['media']),'menus'=>!empty($r['menus']),
                'options'=>!empty($r['options']),'tables'=>!empty($r['tables']),
                'batch'=>max(5,min(100,absint($r['batch']??25)))
            ],
            'cursor'=>['posts'=>0,'postmeta'=>0,'terms'=>0,'termmeta'=>0,'relationships'=>0,'users'=>0,'usermeta'=>0,'comments'=>0,'commentmeta'=>0,'media'=>0],
            'done'=>[],'logs'=>[],'package'=>['meta'=>['generator'=>'GoMigrate','version'=>self::VERSION,'site_url'=>home_url('/'),'created'=>current_time('mysql'),'db_prefix'=>$wpdb->prefix],'posts'=>[],'postmeta'=>[],'terms'=>[],'termmeta'=>[],'relationships'=>[],'users'=>[],'usermeta'=>[],'comments'=>[],'commentmeta'=>[],'media'=>[],'menus'=>[],'options'=>[],'tables'=>[],'media_manifest'=>[]]
        ];
        $this->save($this->file($job),$state); return $state;
    }
    private function export_step(&$s) {
        global $wpdb; $n=$s['opt']['batch']; $p=&$s['package'];
        if ($s['opt']['posts'] && empty($s['done']['posts'])) {
            if (!$s['post_types']) {$s['done']['posts']=1;} else {
                $ph=implode(',',array_fill(0,count($s['post_types']),'%s')); $a=$s['post_types']; $a[]=$n; $a[]=$s['cursor']['posts'];
                $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE post_type IN ($ph) ORDER BY ID LIMIT %d OFFSET %d",...$a),ARRAY_A);
                $this->add($p,'posts',$rows); $s['cursor']['posts']+=count($rows); $this->log_export_batch($s,'posts',$rows); if(count($rows)<$n)$s['done']['posts']=1;
            }
            $this->save($this->file($s['job']),$s); return $this->progress($s);
        }
        if ($s['opt']['postmeta'] && empty($s['done']['postmeta'])) {
            $rows=$wpdb->get_results($wpdb->prepare("SELECT pm.* FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type NOT IN('revision','nav_menu_item') ORDER BY pm.meta_id LIMIT %d OFFSET %d",$n,$s['cursor']['postmeta']),ARRAY_A);
            $this->add($p,'postmeta',$rows); $s['cursor']['postmeta']+=count($rows); $this->log_export_batch($s,'postmeta',$rows); if(count($rows)<$n)$s['done']['postmeta']=1;
            $this->save($this->file($s['job']),$s); return $this->progress($s);
        }
        if ($s['opt']['terms'] && empty($s['done']['terms'])) {
            if (!$s['taxonomies']) {$s['done']['terms']=1;} else {
                $ph=implode(',',array_fill(0,count($s['taxonomies']),'%s')); $a=$s['taxonomies']; $a[]=$n; $a[]=$s['cursor']['terms'];
                $rows=$wpdb->get_results($wpdb->prepare("SELECT tt.*,t.term_id,t.name,t.slug,t.term_group FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id=tt.term_id WHERE tt.taxonomy IN($ph) ORDER BY t.term_id LIMIT %d OFFSET %d",...$a),ARRAY_A);
                $this->add($p,'terms',$rows); $s['cursor']['terms']+=count($rows); $this->log_export_batch($s,'terms',$rows); if(count($rows)<$n)$s['done']['terms']=1;
            }
            $this->save($this->file($s['job']),$s); return $this->progress($s);
        }
        if ($s['opt']['terms'] && empty($s['done']['termmeta'])) {
            $rows=$wpdb->get_results($wpdb->prepare("SELECT tm.* FROM {$wpdb->termmeta} tm ORDER BY tm.meta_id LIMIT %d OFFSET %d",$n,$s['cursor']['termmeta']),ARRAY_A);
            $this->add($p,'termmeta',$rows); $s['cursor']['termmeta']+=count($rows); $this->log_export_batch($s,'termmeta',$rows); if(count($rows)<$n)$s['done']['termmeta']=1;
            $this->save($this->file($s['job']),$s); return $this->progress($s);
        }
        if ($s['opt']['terms'] && empty($s['done']['relationships'])) {
            $rows=$wpdb->get_results($wpdb->prepare("SELECT tr.object_id,tr.term_taxonomy_id,tt.term_id,tt.taxonomy FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id INNER JOIN {$wpdb->posts} p ON p.ID=tr.object_id WHERE p.post_type NOT IN('revision','nav_menu_item') ORDER BY tr.object_id,tr.term_taxonomy_id LIMIT %d OFFSET %d",$n,$s['cursor']['relationships']),ARRAY_A);
            $this->add($p,'relationships',$rows); $s['cursor']['relationships']+=count($rows); $this->log_export_batch($s,'relationships',$rows); if(count($rows)<$n)$s['done']['relationships']=1;
            $this->save($this->file($s['job']),$s); return $this->progress($s);
        }
        foreach(['users'=>'users','usermeta'=>'usermeta','comments'=>'comments','commentmeta'=>'commentmeta'] as $key=>$tablekey) {
            if (!$s['opt'][$key] || !empty($s['done'][$key])) continue;
            $table=$wpdb->{$tablekey}; $idcol=['users'=>'ID','usermeta'=>'umeta_id','comments'=>'comment_ID','commentmeta'=>'meta_id'][$key];
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM %i ORDER BY %i LIMIT %d OFFSET %d",$table,$idcol,$n,$s['cursor'][$key]),ARRAY_A);
            $this->add($p,$key,$rows); $s['cursor'][$key]+=count($rows); $this->log_export_batch($s,$key,$rows); if(count($rows)<$n)$s['done'][$key]=1;
            $this->save($this->file($s['job']),$s); return $this->progress($s);
        }
        if ($s['opt']['media'] && empty($s['done']['media'])) {
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE post_type='attachment' ORDER BY ID LIMIT %d OFFSET %d",$n,$s['cursor']['media']),ARRAY_A);
            foreach($rows as $row) $p['media_manifest'][]=['id'=>(int)$row['ID'],'file'=>(string)get_post_meta((int)$row['ID'],'_wp_attached_file',true),'url'=>wp_get_attachment_url((int)$row['ID']),'mime'=>$row['post_mime_type']];
            $this->add($p,'media',$rows); $s['cursor']['media']+=count($rows); $this->log_export_batch($s,'media',$rows); if(count($rows)<$n)$s['done']['media']=1;
            $this->save($this->file($s['job']),$s); return $this->progress($s);
        }
        if ($s['opt']['menus'] && empty($s['done']['menus'])) {
            foreach(wp_get_nav_menus() as $m) $p['menus'][]=['menu'=>(array)$m,'items'=>array_map('get_object_vars',(array)wp_get_nav_menu_items($m->term_id))];
            $this->add_log($s, 'Navigation menus: ' . count($p['menus']) . ' menus prepared.', 'success');
            $s['done']['menus']=1; $this->save($this->file($s['job']),$s); return $this->progress($s);
        }
        if ($s['opt']['options'] && empty($s['done']['options'])) {
            $p['options']=$wpdb->get_results("SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options}",ARRAY_A); $this->add_log($s, 'WordPress options: ' . count($p['options']) . ' options prepared.', 'success'); $s['done']['options']=1;
            $this->save($this->file($s['job']),$s); return $this->progress($s);
        }
        if ($s['opt']['tables'] && empty($s['done']['tables'])) {
            $allowed=$this->tables();
            foreach($s['plugin_tables'] as $t) if(isset($allowed[$t])) $p['tables'][]=['table'=>$t,'rows'=>$wpdb->get_results($wpdb->prepare("SELECT * FROM %i",$t),ARRAY_A)];
            $this->add_log($s, 'Plugin/custom tables: ' . count($p['tables']) . ' tables prepared.', 'success');
            $s['done']['tables']=1; $this->save($this->file($s['job']),$s); return $this->progress($s);
        }
        $this->add_log($s, 'Export package is ready for download.', 'success'); $s['done']['all']=1; $this->save($this->file($s['job']),$s); return $this->progress($s);
    }
    private function current_status($s) {
        if (!empty($s['done']['all'])) {
            return __('Completed', 'gomigrate');
        }
        $order = $s['type'] === 'export'
            ? ['posts','postmeta','terms','termmeta','relationships','users','usermeta','comments','commentmeta','media','menus','options','tables']
            : ['users','terms','termmeta','posts','postmeta','usermeta','comments','commentmeta','post_links','comment_parents','menus','options','tables'];
        foreach ($order as $key) {
            if (!empty($s['opt'][$key]) || $s['type'] === 'import') {
                if (empty($s['done'][$key])) {
                    return ucfirst(str_replace('_', ' ', $key));
                }
            }
        }
        return __('Processing...', 'gomigrate');
    }

    private function progress($s) {
        $keys=['posts','postmeta','terms','termmeta','relationships','users','usermeta','comments','commentmeta','media','menus','options','tables']; $done=0;
        foreach($keys as $k) if(!empty($s['done'][$k]) || empty($s['opt'][$k])) $done++;
        $pct=(int)floor(($done/count($keys))*100); return ['percent'=>min(100,$pct),'complete'=>!empty($s['done']['all'])];
    }
    private function new_import($r) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- AJAX entry points validate the GoMigrate nonce before calling this importer.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Uploaded files must be read from $_FILES; type and upload status are validated below.
        $file = isset($_FILES['package']) && is_array($_FILES['package']) ? wp_unslash($_FILES['package']) : [];
        $tmp_name = isset($file['tmp_name']) ? sanitize_text_field($file['tmp_name']) : '';
        $error = isset($file['error']) ? absint($file['error']) : UPLOAD_ERR_NO_FILE;

        if (UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error) {
            throw new Exception(esc_html__('The JSON file is larger than the server upload limit. Increase upload_max_filesize and post_max_size, then try again.', 'gomigrate'));
        }
        if (UPLOAD_ERR_PARTIAL === $error) {
            throw new Exception(esc_html__('The JSON upload was incomplete. Please upload the file again.', 'gomigrate'));
        }
        if (!$tmp_name || UPLOAD_ERR_OK !== $error || !is_uploaded_file($tmp_name)) {
            throw new Exception(esc_html__('Select a valid JSON export package.', 'gomigrate'));
        }

        $size = @filesize($tmp_name);
        if ($size !== false && $size > 0 && $size > 1024 * 1024 * 1024) {
            throw new Exception(esc_html__('The JSON package is larger than 1 GB and cannot be safely processed in one PHP request. Split the migration or raise the server memory limit.', 'gomigrate'));
        }

        $raw = file_get_contents($tmp_name);
        if ($raw === false || $raw === '') {
            throw new Exception(esc_html__('Unable to read the uploaded JSON package.', 'gomigrate'));
        }

        $package = json_decode($raw, true);
        if (!is_array($package)) {
            $json_error = function_exists('json_last_error_msg') ? json_last_error_msg() : 'Invalid JSON';
            throw new Exception(sprintf(esc_html__('Invalid GoMigrate JSON: %s', 'gomigrate'), esc_html($json_error)));
        }

        /*
         * GoMigrate 1.0.3 accidentally downloaded the complete export job
         * state instead of the nested migration package. Accept that format
         * for backward compatibility, while new exports use the package at
         * the JSON root.
         */
        if (empty($package['meta']) && !empty($package['package']) && is_array($package['package'])) {
            $package = $package['package'];
        }

        if (empty($package['meta']) || !is_array($package['meta'])) {
            throw new Exception(esc_html__('Invalid GoMigrate package: the JSON does not contain a valid migration package.', 'gomigrate'));
        }

        $source_db_prefix = isset($package['meta']['db_prefix']) ? sanitize_key($package['meta']['db_prefix']) : '';

        $job = 'import-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false);
        $package_dir = $this->package_dir($job);

        /*
         * Do not put the decoded package into the job state. The previous
         * importer did that, which made every subsequent AJAX request load,
         * encode and write the complete migration package again. Large JSON
         * packages could therefore hit PHP memory/time limits and appear in
         * the browser only as "Request failed".
         */
        $parts = [
            'users', 'terms', 'termmeta', 'relationships', 'posts', 'postmeta',
            'usermeta', 'comments', 'commentmeta', 'menus', 'options', 'tables',
            'media', 'media_manifest'
        ];

        $meta_file = trailingslashit($package_dir) . 'meta.json';
        if (false === file_put_contents($meta_file, wp_json_encode((array) $package['meta'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))) {
            $this->cleanup_import_package(['package_dir' => $package_dir]);
            throw new Exception(esc_html__('Unable to create the temporary GoMigrate package directory. Check the uploads directory permissions.', 'gomigrate'));
        }

        foreach ($parts as $key) {
            $value = isset($package[$key]) && is_array($package[$key]) ? $package[$key] : [];
            $encoded = wp_json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if ($encoded === false || false === file_put_contents(trailingslashit($package_dir) . $key . '.json', $encoded)) {
                $this->cleanup_import_package(['package_dir' => $package_dir]);
                throw new Exception(sprintf(esc_html__('Unable to prepare the %s section of the import package.', 'gomigrate'), esc_html($key)));
            }
            unset($package[$key]);
        }
        unset($package, $raw);

        $state = [
            'job' => $job,
            'type' => 'import',
            'created' => time(),
            'package_dir' => $package_dir,
            'source_db_prefix' => $source_db_prefix,
            'mode' => sanitize_key($r['mode'] ?? 'update'),
            'from' => esc_url_raw($r['from'] ?? ''),
            'to' => esc_url_raw($r['to'] ?? home_url('/')),
            'batch' => max(5, min(100, absint($r['batch'] ?? 25))),
            'cursor' => [
                'users'=>0,'terms'=>0,'termmeta'=>0,'relationships'=>0,'posts'=>0,
                'postmeta'=>0,'usermeta'=>0,'comments'=>0,'commentmeta'=>0
            ],
            'done' => [],
            'logs' => [],
            'map' => ['users'=>[],'terms'=>[],'posts'=>[],'comments'=>[]]
        ];

        $this->add_log($state, 'JSON package uploaded and prepared for batched import.', 'success');
        if (!$this->save($this->file($job), $state)) {
            $this->cleanup_import_package($state);
            throw new Exception(esc_html__('Unable to create the GoMigrate import job. Check the uploads directory permissions.', 'gomigrate'));
        }
        return $state;
    }
    private function import_step(&$s) {
        $n=$s['batch']; $c=&$s['cursor']; $m=&$s['map']; $from=$s['from']; $to=$s['to']; global $wpdb;
        /*
         * Load only the section needed for this AJAX step. This keeps memory
         * usage low even when the migration contains many posts/meta rows.
         */
        $part = '';
        foreach (['users','terms','termmeta','posts','postmeta','usermeta','comments','commentmeta','options','tables'] as $candidate) {
            if (empty($s['done'][$candidate])) { $part = $candidate; break; }
        }
        $p = [];
        if ($part) {
            $p[$part] = $this->load_package_part($s, $part);
        }
        if(empty($s['done']['users'])) { $rows=array_slice($p['users']??[],$c['users'],$n); foreach($rows as $r){$old=(int)$r['ID'];$u=get_user_by('email',$r['user_email']);if($u){$m['users'][$old]=(int)$u->ID;continue;}$login=sanitize_user($r['user_login'],true);if(username_exists($login))$login.='_'.wp_generate_password(4,false,false); global $wpdb; $wpdb->insert($wpdb->users,['user_login'=>$login,'user_pass'=>$r['user_pass'],'user_nicename'=>$r['user_nicename'],'user_email'=>$r['user_email'],'user_url'=>$r['user_url'],'user_registered'=>$r['user_registered'],'user_activation_key'=>$r['user_activation_key'],'user_status'=>$r['user_status'],'display_name'=>$r['display_name']]);$id=(int)$wpdb->insert_id;if($id){$m['users'][$old]=$id;}}$c['users']+=count($rows);$this->log_import_batch($s,'users',$rows);if(count($rows)<$n)$s['done']['users']=1;$this->save($this->file($s['job']),$s);return $this->progress_import($s); }
        if(empty($s['done']['terms'])) { $rows=array_slice($p['terms']??[],$c['terms'],$n); foreach($rows as $r){$x=term_exists($r['slug'],$r['taxonomy']);if(!$x){$x=wp_insert_term($r['name'],$r['taxonomy'],['slug'=>$r['slug'],'description'=>$r['description']??'']);}if(!is_wp_error($x)&&$x)$m['terms'][(int)$r['term_id']]=(int)(is_array($x)?$x['term_id']:$x);}$c['terms']+=count($rows);$this->log_import_batch($s,'terms',$rows);if(count($rows)<$n)$s['done']['terms']=1;$this->save($this->file($s['job']),$s);return $this->progress_import($s); }
        if(empty($s['done']['termmeta'])) { $rows=array_slice($p['termmeta']??[],$c['termmeta'],$n); foreach($rows as $r){$tid=$m['terms'][(int)$r['term_id']]??0;if($tid)update_term_meta($tid,$r['meta_key'],$this->replace(maybe_unserialize($r['meta_value']),$from,$to));}$c['termmeta']+=count($rows);$this->log_import_batch($s,'termmeta',$rows);if(count($rows)<$n)$s['done']['termmeta']=1;$this->save($this->file($s['job']),$s);return $this->progress_import($s); }
        if(empty($s['done']['posts'])) { $rows=array_slice($p['posts']??[],$c['posts'],$n); foreach($rows as $r){$old=(int)$r['ID'];$existing=$s['mode']==='new'?0:$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_name=%s LIMIT 1",$r['post_type'],$r['post_name']));$data=$r;unset($data['ID'],$data['guid']);$data=$this->replace($data,$from,$to);if($existing&&$s['mode']==='skip'){$m['posts'][$old]=(int)$existing;continue;}if($existing&&$s['mode']==='update'){$wpdb->update($wpdb->posts,$data,['ID'=>(int)$existing]);$m['posts'][$old]=(int)$existing;}else{$wpdb->insert($wpdb->posts,$data);$m['posts'][$old]=(int)$wpdb->insert_id;}}$c['posts']+=count($rows);$this->log_import_batch($s,'posts',$rows);if(count($rows)<$n)$s['done']['posts']=1;$this->save($this->file($s['job']),$s);return $this->progress_import($s); }
        if(empty($s['done']['postmeta'])) { $rows=array_slice($p['postmeta']??[],$c['postmeta'],$n); foreach($rows as $r){$pid=$m['posts'][(int)$r['post_id']]??0;if(!$pid)continue;$v=$this->replace(maybe_unserialize($r['meta_value']),$from,$to);update_post_meta($pid,$r['meta_key'],$v);}$c['postmeta']+=count($rows);$this->log_import_batch($s,'postmeta',$rows);if(count($rows)<$n)$s['done']['postmeta']=1;$this->save($this->file($s['job']),$s);return $this->progress_import($s); }
        if(empty($s['done']['relationships'])) {
            $all=$this->load_package_part($s,'relationships');
            $rows=array_slice($all, (int)$c['relationships'], $n);
            foreach($rows as $r){
                $pid=$m['posts'][(int)($r['object_id']??0)]??0;
                $tid=$m['terms'][(int)($r['term_id']??0)]??0;
                $taxonomy=isset($r['taxonomy'])?sanitize_key($r['taxonomy']):'';
                if($pid && $tid && $taxonomy){
                    wp_set_object_terms((int)$pid, [(int)$tid], $taxonomy, true);
                }
            }
            $c['relationships']+=count($rows);
            $this->log_import_batch($s,'relationships',$rows);
            if(count($rows)<$n)$s['done']['relationships']=1;
            $this->save($this->file($s['job']),$s);
            return $this->progress_import($s);
        }
        if(empty($s['done']['usermeta'])) { $rows=array_slice($p['usermeta']??[],$c['usermeta'],$n); foreach($rows as $r){$uid=$m['users'][(int)$r['user_id']]??0;if($uid){$meta_key=$r['meta_key']; global $wpdb; $old_prefix=preg_replace('/capabilities$/','',$meta_key); if($old_prefix!==$meta_key && substr($meta_key,-12)==='capabilities') $meta_key=$wpdb->prefix.'capabilities'; elseif($old_prefix!==$meta_key && substr($meta_key,-10)==='user_level') $meta_key=$wpdb->prefix.'user_level'; update_user_meta($uid,$meta_key,$this->replace(maybe_unserialize($r['meta_value']),$from,$to));}}$c['usermeta']+=count($rows);$this->log_import_batch($s,'usermeta',$rows);if(count($rows)<$n)$s['done']['usermeta']=1;$this->save($this->file($s['job']),$s);return $this->progress_import($s); }
        if(empty($s['done']['comments'])) { $rows=array_slice($p['comments']??[],$c['comments'],$n); foreach($rows as $r){$pid=$m['posts'][(int)$r['comment_post_ID']]??0;if(!$pid)continue;$id=wp_insert_comment(['comment_post_ID'=>$pid,'comment_author'=>$r['comment_author'],'comment_author_email'=>$r['comment_author_email'],'comment_author_url'=>$r['comment_author_url'],'comment_author_IP'=>$r['comment_author_IP'],'comment_date'=>$r['comment_date'],'comment_date_gmt'=>$r['comment_date_gmt'],'comment_content'=>$this->replace($r['comment_content'],$from,$to),'comment_karma'=>$r['comment_karma'],'comment_approved'=>$r['comment_approved'],'comment_agent'=>$r['comment_agent'],'comment_type'=>$r['comment_type'],'user_id'=>$m['users'][(int)$r['user_id']]??0]);if($id)$m['comments'][(int)$r['comment_ID']]=(int)$id;}$c['comments']+=count($rows);$this->log_import_batch($s,'comments',$rows);if(count($rows)<$n)$s['done']['comments']=1;$this->save($this->file($s['job']),$s);return $this->progress_import($s); }
        if(empty($s['done']['commentmeta'])) { $rows=array_slice($p['commentmeta']??[],$c['commentmeta'],$n); foreach($rows as $r){$cid=$m['comments'][(int)$r['comment_id']]??0;if($cid)update_comment_meta($cid,$r['meta_key'],maybe_unserialize($r['meta_value']));}$c['commentmeta']+=count($rows);$this->log_import_batch($s,'commentmeta',$rows);if(count($rows)<$n)$s['done']['commentmeta']=1;$this->save($this->file($s['job']),$s);return $this->progress_import($s); }
        if(empty($s['done']['post_links'])) { $this->finalize_post_links($s); $this->add_log($s, 'Post author and parent relationships finalized.', 'success'); $this->save($this->file($s['job']),$s); return $this->progress_import($s); }
        if(empty($s['done']['comment_parents'])) { $this->finalize_comment_parents($s); $this->add_log($s, 'Comment parent relationships finalized.', 'success'); $this->save($this->file($s['job']),$s); return $this->progress_import($s); }
        if(empty($s['done']['menus'])) { $this->import_menus($s); $this->add_log($s, 'Navigation menus imported.', 'success'); $this->save($this->file($s['job']),$s); return $this->progress_import($s); }
        if(empty($s['done']['options'])) { $skip=['siteurl','home','active_plugins','cron','admin_email']; foreach(($p['options']??[]) as $r){if(in_array($r['option_name'],$skip,true))continue;update_option($r['option_name'],$this->replace(maybe_unserialize($r['option_value']),$from,$to),'yes'===$r['autoload']);}$s['done']['options']=1;$this->add_log($s, 'WordPress options imported.', 'success');$this->save($this->file($s['job']),$s);return $this->progress_import($s); }
        if(empty($s['done']['tables'])) { $allowed=$this->tables();$source_prefix=isset($s['source_db_prefix'])?sanitize_key($s['source_db_prefix']):'';foreach(($p['tables']??[]) as $t){$source_name=isset($t['table'])?sanitize_text_field($t['table']):'';$name=$source_name;if($source_prefix && strpos($source_name,$source_prefix)===0)$name=$wpdb->prefix.substr($source_name,strlen($source_prefix));if(!isset($allowed[$name]))continue;$cols=$wpdb->get_col($wpdb->prepare("DESCRIBE %i",$name),0);foreach((array)$t['rows'] as $row){$data=[];foreach($cols as $col)if(array_key_exists($col,$row)){$value=$row[$col];if(is_string($value)){$un=maybe_unserialize($value);$value=$this->replace($un,$from,$to);$value=maybe_serialize($value);}$data[$col]=$value;}if($data){if($s['mode']==='update')$wpdb->replace($name,$data);else{$wpdb->insert($name,$data);}}}}$s['done']['tables']=1;$this->add_log($s, 'Plugin/custom tables imported.', 'success');$this->save($this->file($s['job']),$s);return $this->progress_import($s); }
        if(empty($s['done']['all'])){
            $this->add_log($s, 'Import completed successfully.', 'success');
            $s['done']['all']=1;
            $this->save($this->file($s['job']),$s);
            $this->cleanup_import_package($s);
        }
        return $this->progress_import($s);
    }
    private function import_menus(&$s) {
        if (!empty($s['done']['menus'])) return;
        foreach ((array)$this->load_package_part($s, 'menus') as $menu_data) {
            $menu_name = isset($menu_data['menu']['name']) ? sanitize_text_field($menu_data['menu']['name']) : '';
            if (!$menu_name) continue;
            $existing = wp_get_nav_menu_object($menu_name);
            $menu_id = $existing ? (int)$existing->term_id : (int)wp_create_nav_menu($menu_name);
            if (!$menu_id) continue;
            $items = is_array($menu_data['items']??null) ? $menu_data['items'] : [];
            $item_map = [];
            foreach($items as $item){
                $args = [
                    'menu-item-title'=>isset($item['title']) ? wp_strip_all_tags($item['title']) : '',
                    'menu-item-url'=>isset($item['url']) ? esc_url_raw($this->replace($item['url'],$s['from'],$s['to'])) : '',
                    'menu-item-status'=>'publish',
                    'menu-item-type'=>isset($item['type']) ? sanitize_key($item['type']) : 'custom',
                    'menu-item-attr-title'=>isset($item['attr_title']) ? sanitize_text_field($item['attr_title']) : '',
                    'menu-item-description'=>isset($item['description']) ? sanitize_text_field($item['description']) : '',
                    'menu-item-target'=>isset($item['target']) ? sanitize_text_field($item['target']) : '',
                    'menu-item-classes'=>isset($item['classes']) && is_array($item['classes']) ? array_map('sanitize_html_class',$item['classes']) : [],
                ];
                $type=isset($item['type'])?$item['type']:'custom';
                if($type==='post_type' && !empty($item['object_id']) && isset($s['map']['posts'][(int)$item['object_id']])) {
                    $args['menu-item-object']=isset($item['object'])?sanitize_key($item['object']):'';
                    $args['menu-item-object-id']=(int)$s['map']['posts'][(int)$item['object_id']];
                } elseif($type==='taxonomy' && !empty($item['object_id']) && isset($s['map']['terms'][(int)$item['object_id']])) {
                    $args['menu-item-object']=isset($item['object'])?sanitize_key($item['object']):'';
                    $args['menu-item-object-id']=(int)$s['map']['terms'][(int)$item['object_id']];
                }
                $parent=isset($item['menu_item_parent'])?(int)$item['menu_item_parent']:0;
                if($parent && isset($item_map[$parent])) $args['menu-item-parent']=$item_map[$parent];
                $new_item=wp_update_nav_menu_item($menu_id,0,$args);
                if(!is_wp_error($new_item)) $item_map[(int)($item['ID']??0)]=(int)$new_item;
            }
            if(!empty($menu_data['menu']['term_id']) && isset($menu_data['menu']['name'])) {
                // Menu locations are theme-specific and are intentionally not forced.
            }
        }
        $s['done']['menus']=1;
    }
    private function finalize_post_links(&$s) {
        if (!empty($s['done']['post_links'])) return;
        global $wpdb;
        foreach ($s['map']['posts'] as $old=>$new) {
            $post = get_post((int)$new);
            if (!$post) continue;
            $author = !empty($post->post_author) && isset($s['map']['users'][(int)$post->post_author]) ? (int)$s['map']['users'][(int)$post->post_author] : (int)$post->post_author;
            $parent = !empty($post->post_parent) && isset($s['map']['posts'][(int)$post->post_parent]) ? (int)$s['map']['posts'][(int)$post->post_parent] : (int)$post->post_parent;
            $wpdb->update($wpdb->posts,['post_author'=>$author,'post_parent'=>$parent],['ID'=>(int)$new]);
        }
        $s['done']['post_links']=1;
    }
    private function finalize_comment_parents(&$s) {
        if (!empty($s['done']['comment_parents'])) return;
        global $wpdb;
        $rows=$this->load_package_part($s, 'comments');
        foreach($rows as $r){
            $new=$s['map']['comments'][(int)$r['comment_ID']]??0;
            $parent=$s['map']['comments'][(int)$r['comment_parent']]??0;
            if($new&&$parent) $wpdb->update($wpdb->comments,['comment_parent'=>$parent],['comment_ID'=>$new]);
        }
        $s['done']['comment_parents']=1;
    }
    private function progress_import($s){$keys=['users','terms','termmeta','posts','postmeta','relationships','usermeta','comments','commentmeta','post_links','comment_parents','menus','options','tables'];$d=0;foreach($keys as $k)if(!empty($s['done'][$k]))$d++;return ['percent'=>(int)floor($d/count($keys)*100),'complete'=>!empty($s['done']['all'])];}
    public function ajax_export(){check_ajax_referer('gomigrate_nonce','nonce');if(!current_user_can('manage_options'))wp_send_json_error(['message'=>__('Permission denied', 'gomigrate')],403);try{$job=sanitize_file_name(wp_unslash($_POST['job']??''));$s=$job?$this->load($this->file($job)):$this->new_export(wp_unslash($_POST));if(!$s)throw new Exception(esc_html__('Export job not found.', 'gomigrate'));$r=$this->export_step($s);wp_send_json_success(['job'=>$s['job'],'percent'=>$r['percent'],'complete'=>$r['complete'],'download'=>$r['complete']?$this->download_url($s['job']):'','logs'=>array_slice((array)($s['logs']??[]),-8),'current'=>$this->current_status($s) ]);}catch(Throwable $e){wp_send_json_error(['message'=>$e->getMessage()],500);}}
    public function ajax_import(){check_ajax_referer('gomigrate_nonce','nonce');if(!current_user_can('manage_options'))wp_send_json_error(['message'=>__('Permission denied', 'gomigrate')],403);try{$job=sanitize_file_name(wp_unslash($_POST['job']??''));$s=$job?$this->load($this->file($job)):$this->new_import(wp_unslash($_POST));if(!$s)throw new Exception(esc_html__('Import job not found.', 'gomigrate'));$r=$this->import_step($s);wp_send_json_success(['job'=>$s['job'],'percent'=>$r['percent'],'complete'=>$r['complete'],'logs'=>array_slice((array)($s['logs']??[]),-8),'current'=>$this->current_status($s)]);}catch(Throwable $e){wp_send_json_error(['message'=>$e->getMessage()],500);}}
    public function download(){
        if(!current_user_can('manage_options')||!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce']??'')),'gomigrate_download'))wp_die(esc_html__('Invalid request.', 'gomigrate'));
        $job=sanitize_file_name(wp_unslash($_GET['job']??''));
        $f=$this->file($job);
        if(!is_file($f))wp_die(esc_html__('Package not found.', 'gomigrate'));
        $state=$this->load($f);
        $package=(isset($state['package'])&&is_array($state['package']))?$state['package']:$state;
        if(empty($package['meta']))wp_die(esc_html__('Invalid export package.', 'gomigrate'));
        $json=wp_json_encode($package, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(false===$json)wp_die(esc_html__('Unable to encode the export package as JSON.', 'gomigrate'));
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.sanitize_file_name($job).'.json"');
        header('Content-Length: '.strlen($json));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON must remain valid JSON for the downloaded export package.
        echo $json;
        exit;
    }
    public function assets($hook){
        if($hook!=='tools_page_gomigrate') return;
        wp_enqueue_style('gomigrate-admin', GOMIGRATE_URL . 'assets/css/admin.css', [], self::VERSION);
        wp_enqueue_script('gomigrate-admin', GOMIGRATE_URL . 'assets/js/admin.js', ['jquery'], self::VERSION, true);
        wp_localize_script('gomigrate-admin', 'GoMigrateAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => $this->nonce(),
            'messages' => [
                'operationFailed' => __('Operation failed.', 'gomigrate'),
                'requestFailed' => __('Request failed. Check the PHP/server error log.', 'gomigrate'),
                'exporting' => __('Exporting...', 'gomigrate'),
                'importing' => __('Importing...', 'gomigrate'),
                'completed' => __('Completed.', 'gomigrate'),
                'exportCompleted' => __('Export completed.', 'gomigrate'),
                'importCompleted' => __('Import completed.', 'gomigrate'),
                'download' => __('Download Export Package', 'gomigrate'),
            ],
        ]);
    }
    public function menu(){add_management_page(esc_html__('GoMigrate','gomigrate'),esc_html__('GoMigrate','gomigrate'),'manage_options','gomigrate',[$this,'page']);}
    public function page(){if(!current_user_can('manage_options'))return;$types=$this->post_types();$tax=$this->taxonomies();$tables=$this->tables();$nonce=$this->nonce();?>
<div class="wrap"><h1><?php echo esc_html__('GoMigrate', 'gomigrate'); ?></h1><div id="gomigrate-msg" class="gomigrate-message" hidden></div><p><button class="button button-primary gomigrate-tab" data-tab="export"><?php echo esc_html__('Export', 'gomigrate'); ?></button> <button class="button gomigrate-tab" data-tab="import"><?php echo esc_html__('Import', 'gomigrate'); ?></button></p>
<div id="gomigrate-export"><h2><?php echo esc_html__('Export', 'gomigrate'); ?></h2><form id="gomigrate-export-form">
<table class="form-table"><tr><th><?php echo esc_html__('Posts / Pages / Custom Post Types', 'gomigrate'); ?></th><td><label><input type="checkbox" name="posts" checked> Enable</label><div class="gomigrate-box"><?php foreach($types as $k=>$v):?><label><input type="checkbox" name="post_types[]" value="<?php echo esc_attr($k);?>" checked> <b><?php echo esc_html($k);?></b> — <?php echo esc_html($v);?></label><br><?php endforeach;?></div><label><input type="checkbox" name="postmeta" checked> Post metadata</label></td></tr>
<tr><th><?php echo esc_html__('Taxonomies', 'gomigrate'); ?></th><td><label><input type="checkbox" name="terms" checked> Terms / categories / tags</label><div class="gomigrate-box"><?php foreach($tax as $k=>$v):?><label><input type="checkbox" name="taxonomies[]" value="<?php echo esc_attr($k);?>" checked> <b><?php echo esc_html($k);?></b> — <?php echo esc_html($v);?></label><br><?php endforeach;?></div></td></tr>
<tr><th><?php echo esc_html__('Users', 'gomigrate'); ?></th><td><label><input type="checkbox" name="users" checked> Users</label><br><label><input type="checkbox" name="usermeta" checked> User metadata</label></td></tr>
<tr><th><?php echo esc_html__('Comments', 'gomigrate'); ?></th><td><label><input type="checkbox" name="comments" checked> Comments</label><br><label><input type="checkbox" name="commentmeta" checked> Comment metadata</label></td></tr>
<tr><th><?php echo esc_html__('Media', 'gomigrate'); ?></th><td><label><input type="checkbox" name="media" checked> Attachment records + media manifest (included with content)</label><p class="description">Physical wp-content/uploads files are intentionally not put in the JSON package. Copy uploads separately for large media libraries.</p></td></tr>
<tr><th><?php echo esc_html__('Menus / Options', 'gomigrate'); ?></th><td><label><input type="checkbox" name="menus" checked> Navigation menus</label><br><label><input type="checkbox" name="options"> WordPress options</label></td></tr>
<tr><th><?php echo esc_html__('Plugin Tables', 'gomigrate'); ?></th><td><label><input type="checkbox" name="tables"> Export selected plugin/custom tables</label><div class="gomigrate-box"><?php foreach($tables as $t):?><label><input type="checkbox" name="plugin_tables[]" value="<?php echo esc_attr($t);?>"> <?php echo esc_html($t);?></label><br><?php endforeach;?></div><p class="description">The destination plugin must already be installed and have the same table structure.</p></td></tr>
<tr><th><?php echo esc_html__('Batch Size', 'gomigrate'); ?></th><td><input type="number" name="batch" value="25" min="5" max="100"></td></tr></table><button class="button button-primary button-large"><?php echo esc_html__('Start Export', 'gomigrate'); ?></button></form><div id="exp" class="gomigrate-progress" style="display:none"><div class="gomigrate-progress__bar"><span class="gomigrate-progress__fill"></span></div><div class="gomigrate-progress__meta"><b class="gomigrate-progress__percent">0%</b><span class="gomigrate-progress__current"></span></div><p class="gomigrate-progress__status">Preparing...</p><div class="gomigrate-live-preview"><strong>Activity Preview</strong><div class="gomigrate-live-preview__list" aria-live="polite"></div></div><a class="download"></a></div></div>
<div id="gomigrate-import" style="display:none"><h2><?php echo esc_html__('Import', 'gomigrate'); ?></h2><form id="gomigrate-import-form" enctype="multipart/form-data"><table class="form-table"><tr><th><?php echo esc_html__('JSON Package', 'gomigrate'); ?></th><td><input type="file" name="package" accept=".json" required></td></tr><tr><th><?php echo esc_html__('Import Mode', 'gomigrate'); ?></th><td><select name="mode"><option value="update">Update matching</option><option value="skip">Skip matching</option><option value="new">Always create new</option></select></td></tr><tr><th><?php echo esc_html__('Old URL', 'gomigrate'); ?></th><td><input class="regular-text" type="url" name="from" placeholder="https://old-site.com"></td></tr><tr><th><?php echo esc_html__('New URL', 'gomigrate'); ?></th><td><input class="regular-text" type="url" name="to" value="<?php echo esc_attr(home_url('/'));?>"></td></tr><tr><th><?php echo esc_html__('Batch Size', 'gomigrate'); ?></th><td><input type="number" name="batch" value="25" min="5" max="100"></td></tr></table><button class="button button-primary button-large"><?php echo esc_html__('Start Import', 'gomigrate'); ?></button></form><div id="imp" class="gomigrate-progress" style="display:none"><div class="gomigrate-progress__bar"><span class="gomigrate-progress__fill"></span></div><div class="gomigrate-progress__meta"><b class="gomigrate-progress__percent">0%</b><span class="gomigrate-progress__current"></span></div><p class="gomigrate-progress__status">Preparing...</p><div class="gomigrate-live-preview"><strong>Activity Preview</strong><div class="gomigrate-live-preview__list" aria-live="polite"></div></div></div></div>
</div><?php }
}
