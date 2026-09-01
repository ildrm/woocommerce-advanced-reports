<?php
defined( 'ABSPATH' ) || exit;

$page_slug = 'dashboard' === $group ? 'wcar-reports' : ( 'products' === $group ? 'wcar-product-reports' : ( 'orders' === $group ? 'wcar-order-reports' : 'wcar-customer-reports' ) );
$settings = wp_parse_args( (array) get_option( 'wcar_settings', array() ), \WCAR\Installer::default_settings() );
$default_export = in_array( $settings['export_format'] ?? '', array( 'csv', 'xlsx' ), true ) ? $settings['export_format'] : 'xlsx';
$date_type = 'jalali' === $calendar->type() ? 'text' : 'date';
$date_from = $calendar->format_input( $filter->from );
$date_to   = $calendar->format_input( $filter->to );
$storage_filters = $filter->to_storage_array( $calendar );
$category_terms = get_terms( array( 'taxonomy'=>'product_cat', 'hide_empty'=>false ) );
if ( is_wp_error( $category_terms ) ) { $category_terms = array(); }
$money_keys = array( 'gross_sales','net_sales','net_collected','refunds','refund','discounts','discount','shipping','shipping_revenue','avg_shipping','tax','product_tax','shipping_tax','total_tax','aov','subtotal','total','order_total','revenue','order_value','refund_amount','regular_price','sale_price','price','retail_value','cost_value','gross_spend','net_spend','lifetime_revenue','avg_selling_price','amount','original_total' );
$format_cell = static function ( string $key, $value, array $row ) use ( $money_keys ) {
    if ( null === $value ) { return '—'; }
    if ( in_array( $key, $money_keys, true ) && is_numeric( $value ) ) { return \WCAR\Support\Format::money( (float) $value, $row['currency'] ?? '' ); }
    if ( in_array( $key, array( 'repeat_rate', 'refund_rate' ), true ) && is_numeric( $value ) ) { return \WCAR\Support\Format::percent( (float) $value ); }
    if ( is_float( $value ) ) { return \WCAR\Support\Format::decimal( $value ); }
    if ( is_array( $value ) ) { return implode( ', ', array_map( 'strval', $value ) ); }
    return (string) $value;
};
?>
<div class="wrap wcar-wrap" id="wcar-report-root" data-report-id="<?php echo esc_attr($report_id); ?>">
    <div class="wcar-print-header">
        <?php if ( ! empty( $settings['print_logo'] ) ) : ?><img src="<?php echo esc_url( $settings['print_logo'] ); ?>" alt=""><?php endif; ?>
        <div><strong><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong><br><?php echo esc_html( $definition['title'] ); ?><br><small><?php echo esc_html(sprintf(__('Generated: %s','woocommerce-advanced-reports'),wp_date('Y-m-d H:i:s',null,wp_timezone()))); ?></small><?php if($raw_filters): ?><br><small><?php echo esc_html(sprintf(__('Filters: %s','woocommerce-advanced-reports'),wp_json_encode($raw_filters,JSON_UNESCAPED_UNICODE))); ?></small><?php endif; ?></div>
    </div>
    <div class="wcar-title-row">
        <div><h1><?php echo esc_html( $definition['title'] ); ?></h1><p class="description"><?php echo esc_html( sprintf( __( '%1$s to %2$s · Site timezone: %3$s', 'woocommerce-advanced-reports' ), $calendar->format( $filter->from ), $calendar->format( $filter->to ), wp_timezone_string() ) ); ?></p></div>
        <div class="wcar-actions wcar-no-print">
            <?php if ( current_user_can( \WCAR\Security\Capabilities::EXPORT ) ) :
                foreach ( array( 'csv'=>'CSV','xlsx'=>'XLSX' ) as $fmt=>$label ) {
                    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="wcar-inline-form">';
                    wp_nonce_field( 'wcar_export', '_wcar_export_nonce_' . $fmt, false );
                    echo '<input type="hidden" name="action" value="wcar_export"><input type="hidden" name="report_id" value="'.esc_attr($report_id).'"><input type="hidden" name="format" value="'.esc_attr($fmt).'"><input type="hidden" name="filters" value="'.esc_attr(wp_json_encode($storage_filters)).'"><button class="button" type="submit">'.esc_html(sprintf(__('Export %s','woocommerce-advanced-reports'),$label)).'</button></form> ';
                }
            endif; ?>
            <?php if ( current_user_can( \WCAR\Security\Capabilities::EXPORT ) ) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wcar-inline-form"><?php wp_nonce_field('wcar_queue_export','_wcar_queue_nonce',false); ?><input type="hidden" name="action" value="wcar_queue_export"><input type="hidden" name="report_id" value="<?php echo esc_attr($report_id); ?>"><input type="hidden" name="format" value="<?php echo esc_attr($default_export); ?>"><input type="hidden" name="filters" value="<?php echo esc_attr(wp_json_encode($storage_filters)); ?>"><button class="button" type="submit"><?php echo esc_html(sprintf(__('Queue %s','woocommerce-advanced-reports'),strtoupper($default_export))); ?></button></form><?php endif; ?>
            <?php if ( current_user_can( \WCAR\Security\Capabilities::PRINT ) ) : ?><button type="button" class="button button-primary" id="wcar-print"><?php esc_html_e( 'Print', 'woocommerce-advanced-reports' ); ?></button><?php endif; ?>
        </div>
    </div>

    <?php if ( count( $tabs ) > 1 ) : ?><nav class="nav-tab-wrapper wcar-tabs wcar-no-print">
        <?php foreach ( $tabs as $id=>$tab ) : $url=add_query_arg(array_merge(array('page'=>$page_slug,'report'=>$id),$raw_filters),admin_url('admin.php')); ?><a href="<?php echo esc_url($url); ?>" class="nav-tab <?php echo $id===$report_id?'nav-tab-active':''; ?>"><?php echo esc_html($tab['title']); ?></a><?php endforeach; ?>
    </nav><?php endif; ?>

    <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wcar-card wcar-filter-form wcar-no-print">
        <input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>"><input type="hidden" name="report" value="<?php echo esc_attr( $report_id ); ?>">
        <div class="wcar-presets">
        <?php
        $today = new \DateTimeImmutable('today', wp_timezone());
        $ranges = array(
            __('Today','woocommerce-advanced-reports') => array($today,$today->setTime(23,59,59)),
            __('Yesterday','woocommerce-advanced-reports') => array($today->modify('-1 day'),$today->modify('-1 day')->setTime(23,59,59)),
            __('Last 7 Days','woocommerce-advanced-reports') => array($today->modify('-6 days'),$today->setTime(23,59,59)),
            __('Last 30 Days','woocommerce-advanced-reports') => array($today->modify('-29 days'),$today->setTime(23,59,59)),
        );
        if ('jalali' === $calendar->type()) {
            [ $jy,$jm,$jd ]=\WCAR\Calendar\Calendar::gregorian_to_jalali((int)$today->format('Y'),(int)$today->format('n'),(int)$today->format('j'));
            $make_jalali = static function($y,$m,$d){[$gy,$gm,$gd]=\WCAR\Calendar\Calendar::jalali_to_gregorian($y,$m,$d);return new \DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00',$gy,$gm,$gd),wp_timezone());};
            $month_start=$make_jalali($jy,$jm,1); $ny=$jy; $nm=$jm+1; if($nm>12){$nm=1;$ny++;} $month_end=$make_jalali($ny,$nm,1)->modify('-1 second');
            $py=$jy; $pm=$jm-1; if($pm<1){$pm=12;$py--;} $last_start=$make_jalali($py,$pm,1); $last_end=$month_start->modify('-1 second');
            $year_start=$make_jalali($jy,1,1); $year_end=$make_jalali($jy+1,1,1)->modify('-1 second');
        } else {
            $month_start=$today->modify('first day of this month'); $month_end=$today->modify('first day of next month')->modify('-1 second');
            $last_start=$today->modify('first day of last month'); $last_end=$month_start->modify('-1 second');
            $year_start=$today->setDate((int)$today->format('Y'),1,1); $year_end=$year_start->modify('+1 year')->modify('-1 second');
        }
        $ranges[__('This Month','woocommerce-advanced-reports')]=array($month_start,$month_end); $ranges[__('Last Month','woocommerce-advanced-reports')]=array($last_start,$last_end); $ranges[__('This Year','woocommerce-advanced-reports')]=array($year_start,$year_end);
        foreach($ranges as $label=>$range){$args=array_merge(array('page'=>$page_slug,'report'=>$report_id),$raw_filters,array('date_from'=>$calendar->format_input($range[0]),'date_to'=>$calendar->format_input($range[1])));unset($args['paged']);echo '<a class="button button-small" href="'.esc_url(add_query_arg($args,admin_url('admin.php'))).'">'.esc_html($label).'</a> ';}
        ?>
        </div>
        <div class="wcar-filter-grid">
            <label><span><?php esc_html_e('From','woocommerce-advanced-reports'); ?></span><input type="<?php echo esc_attr($date_type); ?>" name="date_from" value="<?php echo esc_attr($date_from); ?>" placeholder="<?php echo 'jalali'===$calendar->type()?'1405/01/01':'2026-01-01'; ?>"></label>
            <label><span><?php esc_html_e('To','woocommerce-advanced-reports'); ?></span><input type="<?php echo esc_attr($date_type); ?>" name="date_to" value="<?php echo esc_attr($date_to); ?>"></label>
            <label><span><?php esc_html_e('Order Status','woocommerce-advanced-reports'); ?></span><select name="status[]" multiple><?php foreach(wc_get_order_statuses() as $key=>$label):$k=str_replace('wc-','',$key);?><option value="<?php echo esc_attr($k); ?>" <?php selected(in_array($k,$filter->statuses,true)); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
            <label><span><?php esc_html_e('Currency','woocommerce-advanced-reports'); ?></span><select name="currency"><option value=""><?php esc_html_e('All (kept separate)','woocommerce-advanced-reports'); ?></option><?php foreach(get_woocommerce_currencies() as $code=>$name):?><option value="<?php echo esc_attr($code); ?>" <?php selected($filter->currency,$code); ?>><?php echo esc_html($code.' — '.$name); ?></option><?php endforeach; ?></select></label>
            <label><span><?php esc_html_e('Customer','woocommerce-advanced-reports'); ?></span><input type="search" name="customer" value="<?php echo esc_attr($filter->customer); ?>" placeholder="<?php esc_attr_e('Name, email, phone or ID','woocommerce-advanced-reports'); ?>"></label>
            <label><span><?php esc_html_e('Country','woocommerce-advanced-reports'); ?></span><select name="country"><option value=""><?php esc_html_e('All countries','woocommerce-advanced-reports'); ?></option><?php foreach(WC()->countries->get_countries() as $code=>$name):?><option value="<?php echo esc_attr($code); ?>" <?php selected($filter->country,$code); ?>><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
        </div>
        <details class="wcar-advanced-filters"><summary><?php esc_html_e('Advanced filters','woocommerce-advanced-reports'); ?></summary><div class="wcar-filter-grid">
            <label><span><?php esc_html_e('Product IDs','woocommerce-advanced-reports'); ?></span><input type="text" name="product_csv" value="<?php echo esc_attr(implode(',',$filter->product_ids)); ?>" placeholder="12,34,56"></label>
            <label><span><?php esc_html_e('Category','woocommerce-advanced-reports'); ?></span><select name="category[]" multiple><?php foreach($category_terms as $term):?><option value="<?php echo esc_attr($term->term_id); ?>" <?php selected(in_array((int)$term->term_id,$filter->category_ids,true)); ?>><?php echo esc_html($term->name); ?></option><?php endforeach; ?></select></label>
            <label><span><?php esc_html_e('Payment Method','woocommerce-advanced-reports'); ?></span><select name="payment_method"><option value=""><?php esc_html_e('All','woocommerce-advanced-reports'); ?></option><?php foreach(WC()->payment_gateways()->payment_gateways() as $gateway):?><option value="<?php echo esc_attr($gateway->id); ?>" <?php selected($filter->payment_method,$gateway->id); ?>><?php echo esc_html($gateway->get_title()); ?></option><?php endforeach; ?></select></label>
            <label><span><?php esc_html_e('Shipping Method Title','woocommerce-advanced-reports'); ?></span><input type="text" name="shipping_method" value="<?php echo esc_attr($filter->shipping_method); ?>"></label>
            <label><span><?php esc_html_e('Coupon','woocommerce-advanced-reports'); ?></span><input type="text" name="coupon" value="<?php echo esc_attr($filter->coupon); ?>"></label>
            <label><span><?php esc_html_e('Customer Type','woocommerce-advanced-reports'); ?></span><select name="customer_type"><option value=""><?php esc_html_e('All','woocommerce-advanced-reports'); ?></option><option value="registered" <?php selected($filter->customer_type,'registered'); ?>><?php esc_html_e('Registered','woocommerce-advanced-reports'); ?></option><option value="guest" <?php selected($filter->customer_type,'guest'); ?>><?php esc_html_e('Guest','woocommerce-advanced-reports'); ?></option></select></label>
            <label><span><?php esc_html_e('Min Order Amount','woocommerce-advanced-reports'); ?></span><input type="number" step="0.01" name="min_amount" value="<?php echo null===$filter->min_amount?'':esc_attr($filter->min_amount); ?>"></label>
            <label><span><?php esc_html_e('Max Order Amount','woocommerce-advanced-reports'); ?></span><input type="number" step="0.01" name="max_amount" value="<?php echo null===$filter->max_amount?'':esc_attr($filter->max_amount); ?>"></label>
            <label><span><?php esc_html_e('Group By','woocommerce-advanced-reports'); ?></span><select name="group_by"><?php foreach(array('hour','day','week','month','quarter','year') as $g):?><option value="<?php echo esc_attr($g); ?>" <?php selected($filter->group_by,$g); ?>><?php echo esc_html(ucfirst($g)); ?></option><?php endforeach; ?></select></label>
            <label><span><?php esc_html_e('Rows per page','woocommerce-advanced-reports'); ?></span><select name="per_page"><?php foreach(array(25,50,100,200) as $n):?><option value="<?php echo $n; ?>" <?php selected($filter->per_page,$n); ?>><?php echo $n; ?></option><?php endforeach; ?></select></label>
            <label><span><?php esc_html_e('Inactive days','woocommerce-advanced-reports'); ?></span><input type="number" name="inactive_days" value="<?php echo esc_attr($filter->inactive_days); ?>"></label>
            <label><span><?php esc_html_e('Dead-stock days','woocommerce-advanced-reports'); ?></span><input type="number" name="dead_stock_days" value="<?php echo esc_attr($filter->dead_stock_days); ?>"></label>
            <label><span><?php esc_html_e('Dead-stock max sold','woocommerce-advanced-reports'); ?></span><input type="number" name="dead_stock_max_sold" value="<?php echo esc_attr($filter->dead_stock_max_sold); ?>"></label>
            <label class="wcar-checkbox"><input type="checkbox" name="compare" value="1" <?php checked($filter->compare); ?>> <span><?php esc_html_e('Compare with previous period','woocommerce-advanced-reports'); ?></span></label>
        </div></details>
        <div class="wcar-filter-actions"><button class="button button-primary" type="submit"><?php esc_html_e('Apply Filters','woocommerce-advanced-reports'); ?></button><a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>$page_slug,'report'=>$report_id),admin_url('admin.php'))); ?>"><?php esc_html_e('Reset','woocommerce-advanced-reports'); ?></a></div>
    </form>

    <?php if($filter->invalid_date_range):?><div class="notice notice-warning inline"><p><?php esc_html_e('The requested date range was invalid, so the configured default range was used.','woocommerce-advanced-reports'); ?></p></div><?php endif; ?>
    <?php if(!empty($data['error'])):?><div class="notice notice-error inline"><p><?php echo esc_html($data['error']); ?></p></div><?php endif; ?>
    <?php if(!empty($data['note'])):?><div class="notice notice-info inline"><p><?php echo esc_html($data['note']); ?></p></div><?php endif; ?>
    <?php if(!empty($data['summary'])):?><div class="wcar-kpis"><?php foreach($data['summary'] as $key=>$value): $label=ucwords(str_replace('_',' ',$key)); $display=$format_cell($key,$value,array('currency'=>$data['summary']['currency']??'')); if('currency'===$key)continue; ?><div class="wcar-kpi"><span><?php echo esc_html($label); ?></span><strong><?php echo esc_html($display); ?></strong><?php if(!empty($data['comparison'])&&isset($data['comparison'][$key])&&is_numeric($value)&&is_numeric($data['comparison'][$key])):$prev=(float)$data['comparison'][$key];$delta=0.0!==$prev?(($value-$prev)/abs($prev))*100:null;?><small><?php echo null===$delta?'—':esc_html(sprintf('%+.1f%%',$delta)); ?> <?php esc_html_e('vs previous','woocommerce-advanced-reports'); ?></small><?php endif;?></div><?php endforeach; ?></div><?php endif; ?>

    <?php if('dashboard'===$report_id && !empty($data['charts'])):?><div class="wcar-chart-grid"><div class="wcar-card"><h2><?php esc_html_e('Sales Trend','woocommerce-advanced-reports'); ?></h2><canvas class="wcar-chart" data-chart="trend"></canvas></div><div class="wcar-card"><h2><?php esc_html_e('Order Status','woocommerce-advanced-reports'); ?></h2><canvas class="wcar-chart" data-chart="status"></canvas></div><div class="wcar-card"><h2><?php esc_html_e('Top Products','woocommerce-advanced-reports'); ?></h2><canvas class="wcar-chart" data-chart="products"></canvas></div><div class="wcar-card"><h2><?php esc_html_e('Customer Mix','woocommerce-advanced-reports'); ?></h2><canvas class="wcar-chart" data-chart="customers"></canvas></div></div><script type="application/json" id="wcar-chart-data"><?php echo wp_json_encode($data['charts'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON hex escaping makes script-data embedding safe. ?></script><?php endif; ?>

    <?php if(!empty($data['currency_breakdown'])):?><div class="wcar-card"><h2><?php esc_html_e('Currency Breakdown','woocommerce-advanced-reports'); ?></h2><table class="widefat striped"><thead><tr><?php foreach(array_keys($data['currency_breakdown'][0]) as $k):?><th><?php echo esc_html(ucwords(str_replace('_',' ',$k))); ?></th><?php endforeach;?></tr></thead><tbody><?php foreach($data['currency_breakdown'] as $row):?><tr><?php foreach($row as $k=>$v):?><td><?php echo esc_html($format_cell($k,$v,$row)); ?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></div><?php endif; ?>

    <?php if(!empty($data['columns'])):?><div class="wcar-card wcar-table-card"><div class="wcar-table-head"><strong><?php echo esc_html(sprintf(__('%d rows','woocommerce-advanced-reports'),$data['total_rows'])); ?></strong><div class="wcar-table-controls wcar-no-print"><details class="wcar-column-picker"><summary class="button"><?php esc_html_e('Columns','woocommerce-advanced-reports'); ?></summary><div><?php $ci=0;foreach($data['columns'] as $label):?><label><input type="checkbox" class="wcar-column-toggle" data-col-index="<?php echo (int)$ci++; ?>" checked> <?php echo esc_html($label); ?></label><?php endforeach;?></div></details><input class="wcar-table-search" type="search" placeholder="<?php esc_attr_e('Filter visible rows…','woocommerce-advanced-reports'); ?>"></div></div><div class="wcar-table-scroll"><table class="widefat striped wcar-report-table"><thead><tr><?php foreach($data['columns'] as $label):?><th><?php echo esc_html($label); ?></th><?php endforeach;?></tr></thead><tbody><?php foreach($data['rows'] as $row):?><tr><?php foreach(array_keys($data['columns']) as $key):?><td><?php echo esc_html($format_cell($key,$row[$key]??'',$row)); ?></td><?php endforeach;?></tr><?php endforeach;?><?php if(empty($data['rows'])):?><tr><td colspan="<?php echo count($data['columns']); ?>"><?php esc_html_e('No data matched the selected filters.','woocommerce-advanced-reports'); ?></td></tr><?php endif;?></tbody></table></div><?php if($data['max_pages']>1):?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post(paginate_links(array('base'=>add_query_arg('paged','%#%'),'format'=>'','current'=>$data['page'],'total'=>$data['max_pages'],'prev_text'=>'‹','next_text'=>'›'))); ?></div></div><?php endif;?></div><?php endif; ?>

    <div class="wcar-tools-grid wcar-no-print">
        <div class="wcar-card"><h2><?php esc_html_e('Save this report','woocommerce-advanced-reports'); ?></h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wcar_save_report','_wcar_save_nonce',false); ?><input type="hidden" name="action" value="wcar_save_report"><input type="hidden" name="report_id" value="<?php echo esc_attr($report_id); ?>"><input type="hidden" name="filters" value="<?php echo esc_attr(wp_json_encode($storage_filters)); ?>"><input type="text" name="name" maxlength="190" required placeholder="<?php esc_attr_e('e.g. Monthly Mobile Sales','woocommerce-advanced-reports'); ?>"> <button class="button" type="submit"><?php esc_html_e('Save Report','woocommerce-advanced-reports'); ?></button></form></div>
        <?php if(current_user_can(\WCAR\Security\Capabilities::EXPORT)):?><div class="wcar-card"><h2><?php esc_html_e('Schedule this report','woocommerce-advanced-reports'); ?></h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wcar-schedule-form"><?php wp_nonce_field('wcar_create_schedule','_wcar_schedule_nonce',false); ?><input type="hidden" name="action" value="wcar_create_schedule"><input type="hidden" name="report_id" value="<?php echo esc_attr($report_id); ?>"><input type="hidden" name="filters" value="<?php echo esc_attr(wp_json_encode($storage_filters)); ?>"><input name="name" maxlength="190" required placeholder="<?php esc_attr_e('Schedule name','woocommerce-advanced-reports'); ?>"><input name="recipients" type="text" required value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" placeholder="email@example.com"><select name="cadence"><option value="daily"><?php esc_html_e('Daily','woocommerce-advanced-reports'); ?></option><option value="weekly" selected><?php esc_html_e('Weekly','woocommerce-advanced-reports'); ?></option><option value="monthly"><?php esc_html_e('Monthly','woocommerce-advanced-reports'); ?></option></select><input type="time" name="run_time" value="08:00"><select name="format"><option value="xlsx" <?php selected($default_export,'xlsx'); ?>>XLSX</option><option value="csv" <?php selected($default_export,'csv'); ?>>CSV</option></select><select name="period_mode"><option value="rolling"><?php esc_html_e('Rolling period','woocommerce-advanced-reports'); ?></option><option value="fixed"><?php esc_html_e('Fixed current dates','woocommerce-advanced-reports'); ?></option></select><button class="button" type="submit"><?php esc_html_e('Create Schedule','woocommerce-advanced-reports'); ?></button></form></div><?php endif; ?>
    </div>
</div>
