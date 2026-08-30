<?php
namespace WCAR\Admin;

use WCAR\Calendar\Calendar;
use WCAR\Export\ExportManager;
use WCAR\Query\ReportFilter;
use WCAR\Reports\ReportEngine;
use WCAR\Saved\SavedReports;
use WCAR\Schedule\ScheduledReports;
use WCAR\Support\Format;

final class Pages {
    private ReportEngine $engine; private SavedReports $saved; private ScheduledReports $scheduled; private ExportManager $exports;
    public function __construct( ReportEngine $engine, SavedReports $saved, ScheduledReports $scheduled, ExportManager $exports ) { $this->engine=$engine; $this->saved=$saved; $this->scheduled=$scheduled; $this->exports=$exports; }
    public function dashboard(): void { $this->report_page( 'dashboard', 'dashboard' ); }
    public function products(): void { $this->report_page( 'products', 'product-sales' ); }
    public function orders(): void { $this->report_page( 'orders', 'sales-summary' ); }
    public function customers(): void { $this->report_page( 'customers', 'customer-list' ); }

    private function report_page( string $group, string $default ): void {
        $report_id = sanitize_key( $_GET['report'] ?? $default );
        $definition = $this->engine->registry()->get( $report_id );
        if ( ! $definition || $definition['group'] !== $group ) { $report_id=$default; $definition=$this->engine->registry()->get( $report_id ); }
        $calendar = new Calendar(); $filter = ReportFilter::from_request( $_GET, $calendar ); $data = $this->engine->run( $report_id, $filter, true, true );
        $tabs = $this->engine->registry()->by_group( $group ); $raw_filters = $this->raw_filters();
        include WCAR_DIR . 'templates/report-page.php';
    }

    public function saved(): void {
        $rows = $this->saved->all();
        echo '<div class="wrap wcar-wrap"><h1>' . esc_html__( 'Saved Reports', 'woocommerce-advanced-reports' ) . '</h1><div class="wcar-card"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'woocommerce-advanced-reports' ) . '</th><th>' . esc_html__( 'Report', 'woocommerce-advanced-reports' ) . '</th><th>' . esc_html__( 'Created', 'woocommerce-advanced-reports' ) . '</th><th></th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $filters = json_decode( $row['filters'], true ) ?: array(); $def = $this->engine->registry()->get( $row['report_id'] ); if ( ! $def ) { continue; }
            $group_page = $this->page_for_group( $def['group'] ); $url = add_query_arg( array_merge( array( 'page'=>$group_page, 'report'=>$row['report_id'] ), $filters ), admin_url( 'admin.php' ) );
            $delete = wp_nonce_url( admin_url( 'admin-post.php?action=wcar_delete_saved_report&id=' . (int)$row['id'] ), 'wcar_delete_saved_' . (int)$row['id'] );
            echo '<tr><td><strong>' . esc_html( $row['name'] ) . '</strong></td><td>' . esc_html( $def['title'] ) . '</td><td>' . esc_html( $row['created_at'] ) . '</td><td><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Open', 'woocommerce-advanced-reports' ) . '</a> <a class="button wcar-confirm-delete" href="' . esc_url( $delete ) . '">' . esc_html__( 'Delete', 'woocommerce-advanced-reports' ) . '</a></td></tr>';
        }
        if ( ! $rows ) { echo '<tr><td colspan="4">' . esc_html__( 'No saved reports yet.', 'woocommerce-advanced-reports' ) . '</td></tr>'; }
        echo '</tbody></table></div></div>';
    }

    public function scheduled(): void {
        $rows = $this->scheduled->all();
        echo '<div class="wrap wcar-wrap"><h1>' . esc_html__( 'Scheduled Reports', 'woocommerce-advanced-reports' ) . '</h1><p>' . esc_html__( 'Rolling schedules automatically use yesterday for daily reports, the previous 7 days for weekly reports, and the previous calendar month for monthly reports.', 'woocommerce-advanced-reports' ) . '</p><div class="wcar-card"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'woocommerce-advanced-reports' ) . '</th><th>' . esc_html__( 'Report', 'woocommerce-advanced-reports' ) . '</th><th>' . esc_html__( 'Cadence', 'woocommerce-advanced-reports' ) . '</th><th>' . esc_html__( 'Recipients', 'woocommerce-advanced-reports' ) . '</th><th>' . esc_html__( 'Last Run', 'woocommerce-advanced-reports' ) . '</th><th>' . esc_html__( 'Next Run', 'woocommerce-advanced-reports' ) . '</th><th></th></tr></thead><tbody>';
        foreach ( $rows as $row ) { $def=$this->engine->registry()->get($row['report_id']); $delete=wp_nonce_url(admin_url('admin-post.php?action=wcar_delete_schedule&id='.(int)$row['id']),'wcar_delete_schedule_'.(int)$row['id']); echo '<tr><td><strong>'.esc_html($row['name']).'</strong></td><td>'.esc_html($def['title']??$row['report_id']).'</td><td>'.esc_html(ucfirst($row['cadence'])).' '.esc_html($row['run_time']).'</td><td>'.esc_html($row['recipients']).'</td><td>'.esc_html($row['last_run']?:'—').'</td><td>'.esc_html($row['next_run']?:'—').'</td><td><a class="button wcar-confirm-delete" href="'.esc_url($delete).'">'.esc_html__('Delete','woocommerce-advanced-reports').'</a></td></tr>'; }
        if(!$rows){echo '<tr><td colspan="7">'.esc_html__('No scheduled reports yet. Create one from any report page.','woocommerce-advanced-reports').'</td></tr>';}
        echo '</tbody></table></div></div>';
    }

    public function exports(): void {
        $rows=$this->exports->history(); echo '<div class="wrap wcar-wrap"><h1>'.esc_html__('Export History','woocommerce-advanced-reports').'</h1><div class="wcar-card"><table class="widefat striped"><thead><tr><th>'.esc_html__('Report','woocommerce-advanced-reports').'</th><th>'.esc_html__('Format','woocommerce-advanced-reports').'</th><th>'.esc_html__('Status','woocommerce-advanced-reports').'</th><th>'.esc_html__('Created','woocommerce-advanced-reports').'</th><th>'.esc_html__('Expires','woocommerce-advanced-reports').'</th><th></th></tr></thead><tbody>';
        foreach($rows as $row){$def=$this->engine->registry()->get($row['report_id']); $down=wp_nonce_url(admin_url('admin-post.php?action=wcar_download_export&id='.(int)$row['id']),'wcar_download_export_'.(int)$row['id']); $del=wp_nonce_url(admin_url('admin-post.php?action=wcar_delete_export&id='.(int)$row['id']),'wcar_delete_export_'.(int)$row['id']); echo '<tr><td>'.esc_html($def['title']??$row['report_id']).'</td><td>'.esc_html(strtoupper($row['format'])).'</td><td>'.esc_html(ucfirst($row['status'])).'</td><td>'.esc_html($row['created_at']).'</td><td>'.esc_html($row['expires_at']?:'—').'</td><td>'.('ready'===$row['status']?'<a class="button" href="'.esc_url($down).'">'.esc_html__('Download','woocommerce-advanced-reports').'</a> ':'').'<a class="button wcar-confirm-delete" href="'.esc_url($del).'">'.esc_html__('Delete','woocommerce-advanced-reports').'</a></td></tr>';}
        if(!$rows){echo '<tr><td colspan="6">'.esc_html__('No exports yet.','woocommerce-advanced-reports').'</td></tr>';} echo '</tbody></table></div></div>';
    }

    public function settings(): void {
        $s=wp_parse_args((array)get_option('wcar_settings',array()),\WCAR\Installer::default_settings()); $statuses=wc_get_order_statuses();
        echo '<div class="wrap wcar-wrap"><h1>'.esc_html__('Report Settings','woocommerce-advanced-reports').'</h1><form method="post" action="options.php">'; settings_fields('wcar_settings_group'); echo '<div class="wcar-settings-grid">';
        $this->settings_section_start(__('Calendar & Dates','woocommerce-advanced-reports'));
        $this->select('calendar',__('Calendar','woocommerce-advanced-reports'),array('gregorian'=>'Gregorian','jalali'=>'Jalali (Solar Hijri)'),$s['calendar']);
        $this->text('date_format',__('Gregorian Date Format','woocommerce-advanced-reports'),$s['date_format']); $this->text('jalali_date_format',__('Jalali Date Format','woocommerce-advanced-reports'),$s['jalali_date_format']);
        $this->select('first_day_of_week',__('First Day of Week','woocommerce-advanced-reports'),array('0'=>'Sunday','1'=>'Monday','6'=>'Saturday'),$s['first_day_of_week']); $this->text('default_range',__('Default Date Range (days)','woocommerce-advanced-reports'),$s['default_range'],'number'); $this->settings_section_end();
        $this->settings_section_start(__('Reports & Performance','woocommerce-advanced-reports'));
        echo '<label class="wcar-setting"><span>'.esc_html__('Default Order Statuses','woocommerce-advanced-reports').'</span><select name="wcar_settings[default_statuses][]" multiple size="6">'; foreach($statuses as $key=>$label){$k=str_replace('wc-','',$key);echo '<option value="'.esc_attr($k).'" '.selected(in_array($k,(array)$s['default_statuses'],true),true,false).'>'.esc_html($label).'</option>';} echo '</select></label>';
        $this->text('cache_ttl',__('Cache TTL (seconds)','woocommerce-advanced-reports'),$s['cache_ttl'],'number'); $this->text('batch_size',__('WooCommerce Query Batch Size','woocommerce-advanced-reports'),$s['batch_size'],'number'); $this->text('inactive_days',__('Inactive Customer Days','woocommerce-advanced-reports'),$s['inactive_days'],'number'); $this->text('dead_stock_days',__('Dead-stock Window (days)','woocommerce-advanced-reports'),$s['dead_stock_days'],'number'); $this->text('dead_stock_max_sold',__('Dead-stock Max Units Sold','woocommerce-advanced-reports'),$s['dead_stock_max_sold'],'number'); $this->settings_section_end();
        $this->settings_section_start(__('Privacy & Output','woocommerce-advanced-reports')); $this->select('privacy',__('Customer PII','woocommerce-advanced-reports'),array('full'=>'Full','masked'=>'Partially masked','hidden'=>'Hidden'),$s['privacy']); $this->select('export_format',__('Default Export Format','woocommerce-advanced-reports'),array('xlsx'=>'XLSX','csv'=>'CSV'),$s['export_format']); $this->text('number_decimals',__('Number Decimals','woocommerce-advanced-reports'),$s['number_decimals'],'number'); $this->text('decimal_separator',__('Decimal Separator','woocommerce-advanced-reports'),$s['decimal_separator']); $this->text('thousand_separator',__('Thousand Separator','woocommerce-advanced-reports'),$s['thousand_separator']); echo '<label class="wcar-setting"><span>'.esc_html__('CSV Compatibility','woocommerce-advanced-reports').'</span><label><input type="checkbox" name="wcar_settings[csv_bom]" value="1" '.checked('yes',$s['csv_bom'],false).'> '.esc_html__('Include UTF-8 BOM for Excel/Persian text','woocommerce-advanced-reports').'</label></label>'; $this->text('print_logo',__('Print Logo URL','woocommerce-advanced-reports'),$s['print_logo'],'url'); echo '<label class="wcar-setting"><span>'.esc_html__('Uninstall','woocommerce-advanced-reports').'</span><label><input type="checkbox" name="wcar_settings[delete_data_on_uninstall]" value="1" '.checked('yes',$s['delete_data_on_uninstall']??'',false).'> '.esc_html__('Delete plugin tables/settings when uninstalled','woocommerce-advanced-reports').'</label></label>'; $this->settings_section_end();
        $this->settings_section_start(__('Role Permissions','woocommerce-advanced-reports')); echo '<p class="description">'.esc_html__('Administrators always receive every reporting capability. Configure other roles below.','woocommerce-advanced-reports').'</p>'; $caps=\WCAR\Security\Capabilities::all(); foreach(wp_roles()->roles as $role_name=>$details){ if('administrator'===$role_name)continue; $role=get_role($role_name); echo '<div class="wcar-role-row"><strong>'.esc_html($details['name']).'</strong><div>'; foreach($caps as $cap){ echo '<label><input type="checkbox" name="wcar_settings[role_caps]['.esc_attr($role_name).'][]" value="'.esc_attr($cap).'" '.checked($role&&$role->has_cap($cap),true,false).'> '.esc_html($cap).'</label> '; } echo '</div></div>'; } $this->settings_section_end();
        echo '</div>'; submit_button(); echo '</form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="wcar-inline-form"><input type="hidden" name="action" value="wcar_clear_cache">'.wp_nonce_field('wcar_clear_cache','_wpnonce',true,false).'<button class="button" type="submit">'.esc_html__('Clear Report Cache','woocommerce-advanced-reports').'</button></form></div>';
    }

    private function raw_filters(): array {
        $allowed=array('date_from','date_to','status','product','category','customer','country','payment_method','shipping_method','coupon','min_amount','max_amount','customer_type','currency','group_by','compare','per_page','inactive_days','dead_stock_days','dead_stock_max_sold'); $out=array(); foreach($allowed as $k){if(isset($_GET[$k])){$v=wp_unslash($_GET[$k]);$out[$k]=is_array($v)?array_map('sanitize_text_field',$v):sanitize_text_field($v);}} return $out;
    }
    private function page_for_group(string $group):string{return array('dashboard'=>'wcar-reports','products'=>'wcar-product-reports','orders'=>'wcar-order-reports','customers'=>'wcar-customer-reports')[$group]??'wcar-reports';}
    private function settings_section_start(string $title):void{echo '<section class="wcar-card"><h2>'.esc_html($title).'</h2>';}
    private function settings_section_end():void{echo '</section>';}
    private function text(string $key,string $label,$value,string $type='text'):void{echo '<label class="wcar-setting"><span>'.esc_html($label).'</span><input type="'.esc_attr($type).'" name="wcar_settings['.esc_attr($key).']" value="'.esc_attr($value).'" class="regular-text"></label>';}
    private function select(string $key,string $label,array $options,$value):void{echo '<label class="wcar-setting"><span>'.esc_html($label).'</span><select name="wcar_settings['.esc_attr($key).']">';foreach($options as $k=>$v){echo '<option value="'.esc_attr($k).'" '.selected((string)$value,(string)$k,false).'>'.esc_html($v).'</option>';}echo '</select></label>';}
}
