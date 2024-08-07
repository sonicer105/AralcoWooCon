<?php

defined( 'ABSPATH' ) or die(); // Prevents direct access to file.
require_once "aralco-util.php";
require_once(ABSPATH.'wp-admin/includes/plugin.php');

/**
 * Class Aralco_Processing_Helper
 *
 * Provides helper methods that assist with registering and updating item in the WooCommerce Database.
 */
class Aralco_Processing_Helper {
    /**
     * Checks for changes to products in Aralco and pulls them into WooCommerce.
     *
     * Will only check for new changes that occurred since the last time the product sync was run.
     *
     * Config must be set before this method will function
     *
     * @param bool $everything passing true will pull every product instead of just changed products. THIS WILL TAKE
     * TIME
     * @param null $products
     * @return bool|int|WP_Error|WP_Error[] Returns a int count of the records modified if the update completed successfully,
     * false if no update was due, and a WP_Error instance if something went wrong.
     */
    static function sync_products($everything = false, $products = null) {
        Aralco_WooCommerce_Connector::log_info("Syncing products");
        $chunked = !is_null($products);
        if ($everything) set_time_limit(0); // Required for the amount of data that needs to be fetched
        else set_time_limit(3600);
        if(!$chunked) {
            $options = get_option(ARALCO_SLUG . '_options');
            if (!isset($options[ARALCO_SLUG . '_field_api_location']) || !isset($options[ARALCO_SLUG . '_field_api_token'])) {
                $to_return = new WP_Error(
                    ARALCO_SLUG . '_messages',
                    __('You must save the connection settings before you can sync any data.', ARALCO_SLUG)
                );
                Aralco_WooCommerce_Connector::log_error("Syncing products failed", $to_return);
                return $to_return;
            }

            $result = Aralco_Processing_Helper::process_shipping_product();
            if ($result instanceof WP_Error) {
                Aralco_WooCommerce_Connector::log_error("Process shipping product failed", $result);
                return $result;
            }

            $result = Aralco_Processing_Helper::process_giftcard_product();
            if ($result instanceof WP_Error) {
                Aralco_WooCommerce_Connector::log_error("Process giftcard product failed", $result);
                return $result;
            }

            $lastSync = get_option(ARALCO_SLUG . '_last_sync');
            if (!isset($lastSync) || $lastSync === false || $everything) {
                $lastSync = date("Y-m-d\TH:i:s", mktime(0, 0, 0, 1, 1, 1900));
            }

            $server_time = Aralco_Connection_Helper::getServerTime();
            if ($server_time instanceof WP_Error) {
                Aralco_WooCommerce_Connector::log_error("Getting server time failed", $server_time);
                return $server_time;
            } else if (is_array($server_time) && isset($server_time['UtcOffset'])) {
                $server_time['UtcOffset'] -= 60; // Adds an extra hour to the sync to adjust for server de-syncs
                $sign = ($server_time['UtcOffset'] > 0)? '+' : '-';
                if ($server_time['UtcOffset'] < 0) {
                    $server_time['UtcOffset'] = $server_time['UtcOffset'] * -1;
                }
                $temp = DateTime::createFromFormat('Y-m-d\TH:i:s', $lastSync);
                $temp->modify($sign . $server_time['UtcOffset'] . ' minutes');
                $lastSync = $temp->format('Y-m-d\TH:i:s');
            }

            $result = Aralco_Processing_Helper::process_disabled_products();
            if ($result instanceof WP_Error) {
                Aralco_WooCommerce_Connector::log_error("Processing of disabled products failed", $result);
                return $result;
            }

            $product_updates = Aralco_Connection_Helper::getProducts($lastSync);
            if ($product_updates instanceof WP_Error) {
                Aralco_WooCommerce_Connector::log_error("Get Products failed", $product_updates);
                return $product_updates;
            }
            $products = $product_updates['data'];

            $result = Aralco_Processing_Helper::process_active_promotions();
            if ($result instanceof WP_Error) {
                Aralco_WooCommerce_Connector::log_error("Processing of active promotions failed", $result);
                return $result;
            }

        } else {
            global $temp_shipping_product_code;
            $temp_shipping_product_code = get_option(ARALCO_SLUG . '_shipping_product_code', null);
            global $temp_giftcard_product_code;
            $temp_giftcard_product_code = get_option(ARALCO_SLUG . '_giftcard_product_code', null);
        }

        if(is_array($products)){ // Got Data
            // Sorting the array so items are processed in a consistent order (makes it easier for testing)
            usort($products, function($a, $b){
                return $a['ProductID'] <=> $b['ProductID'];
            });

            $count = 0;
            $errors = array();
            global $temp_supplier_mapping;
            $temp_supplier_mapping = get_option(ARALCO_SLUG . '_supplier_mapping', array());
            foreach($products as $item){
                $count++;
                $result = Aralco_Processing_Helper::process_item($item);
                if ($result instanceof WP_Error) {
                    array_push($errors, $result);
                }
            }

            if(count($errors) > 0){
                return $errors;
            }
        }
        Aralco_WooCommerce_Connector::log_info("Done syncing products");
        return true;
    }

    static function process_shipping_product() {
        /* Get and save shipping product code */
        $shipping_setting = Aralco_Connection_Helper::getSetting('ShippingProductCode');
        if ($shipping_setting instanceof WP_Error) return $shipping_setting;
        global $temp_shipping_product_code;
        if (is_array($shipping_setting) && isset($shipping_setting['Value'])) {
            update_option(ARALCO_SLUG . '_shipping_product_code', $shipping_setting['Value']);
            $temp_shipping_product_code = $shipping_setting['Value'];
        } else {
            delete_option(ARALCO_SLUG . '_shipping_product_code');
            $temp_shipping_product_code = null;
        }
        return true;
    }

    static function process_giftcard_product() {
        /* Get and save giftcard product code */
        $giftcard_setting = Aralco_Connection_Helper::getSetting('GiftCardProductCode', 'UDI');
        if ($giftcard_setting instanceof WP_Error) return $giftcard_setting;
        global $temp_giftcard_product_code;
        if (is_array($giftcard_setting) && isset($giftcard_setting['Value'])) {
            update_option(ARALCO_SLUG . '_giftcard_product_code', $giftcard_setting['Value']);
            $temp_giftcard_product_code = $giftcard_setting['Value'];
        } else {
            delete_option(ARALCO_SLUG . '_giftcard_product_code');
            $temp_giftcard_product_code = null;
        }
        return true;
    }

    static function process_disabled_products() {
        $result = Aralco_Connection_Helper::getDisabledProducts();
        if ($result instanceof WP_Error) return $result;

        if(is_array($result) && count($result) > 0){ // Got Data
            global $wpdb;
            $products = $wpdb->get_results("SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_aralco_id'");
            if(is_array($products) && count($products) > 0){
                $list = array();
                foreach ($products as $i => $product){
                    if(in_array($product->meta_value, $result)){
                        $list[] = $product->post_id;
                    }
                }
                if(count($list) > 0){
                    $list = implode(',', $list);
                    $wpdb->query("UPDATE $wpdb->posts SET post_status = 'trash' WHERE ID IN ($list)");
                }
            }
        }
        return true;
    }

    static function process_active_promotions() {
        $promotions = Aralco_Connection_Helper::getActivePromotions();
        if ($promotions instanceof WP_Error) return $promotions;

        if(is_array($promotions) && count($promotions) > 0){ // Got Data
            
            global $wpdb;

            // Extract aralco_ids from the input array
            $aralco_ids = array_map(function($promotion) {
                return $promotion['productId'];
            }, $promotions);
        
            // Find all products with aralco_id meta key
            $query = "
                SELECT p.ID, pm.meta_value as aralco_id
                FROM $wpdb->posts p
                LEFT JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_aralco_id'
                WHERE p.post_type = 'product' AND p.post_status = 'publish'
            ";
        
            $all_products = $wpdb->get_results($query);
        
            $non_matching_product_ids = [];
        
            // Determine non-matching products
            foreach ($all_products as $product) {
                if (!in_array((int)$product->aralco_id, $aralco_ids)) {
                    $non_matching_product_ids[] = $product->ID;
                }
            }
        
            // Clear sale price for non-matching products
            foreach ($non_matching_product_ids as $product_id) {
                update_post_meta($product_id, '_sale_price', '');
                update_post_meta($product_id, '_sale_price_dates_from', '');
                update_post_meta($product_id, '_sale_price_dates_to', '');
            }
        
            // Loop through the input array and update the sale price and expiration date
            foreach ($promotions as $promotion) {
                $product_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = '_aralco_id' AND meta_value = %d",
                    $promotion['productId']
                ));
        
                if ($product_id) {
                    update_post_meta($product_id, '_sale_price', $promotion['minPrice']);
        
                    // Convert end_date to timestamp respecting WordPress timezone settings
                    $end_date = DateTime::createFromFormat('Y-m-d H:i:s.u', $promotion['endDate']);
                    if ($end_date) {
                        // Adjust the date to WordPress timezone
                        $end_date->setTimezone(new DateTimeZone(wp_timezone_string()));
                        update_post_meta($product_id, '_sale_price_dates_to', $end_date->getTimestamp());
                    } else {
                        update_post_meta($product_id, '_sale_price_dates_to', '');
                    }
                }
            }
        
            // Clear product cache to ensure the changes are reflected immediately
            wc_delete_product_transients();

        }
        return true;
    }

    /**
     * Processes a single item to add or update from Aralco into WooCommerce.
     *
     * @param array $item an associative array containing information about the Aralco product. See the Aralco API
     * documentation for the expected format.
     * @return bool|WP_Error
     */
    static function process_item($item) {
        global $temp_shipping_product_code;
        global $temp_giftcard_product_code;
        $options = get_option(ARALCO_SLUG . '_options');
        if((!is_null($temp_shipping_product_code) && strcasecmp($temp_shipping_product_code, $item['Product']['Code']) == 0) ||
            (!is_null($temp_giftcard_product_code) && strcasecmp($temp_giftcard_product_code, $item['Product']['Code']) == 0)){
            $post_type = 'private';
        } else {
            $post_type = 'publish';
        }

        $returnVal = true;
        $args = array(
            'posts_per_page'    => 1,
            'post_type'         => 'product',
            'meta_key'          => '_aralco_id',
            'meta_value'        => strval($item['ProductID']),
            'post_status'       => array('publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', 'trash')
        );

        $results = (new WP_Query($args))->get_posts();
        $is_new = count($results) <= 0;

        if(!isset($item['Product']['Description'])){
            $item['Product']['Description'] = 'No Description';
        }
        if(!isset($item['Product']['SeoDescription'])){
            $item['Product']['SeoDescription'] = $item['Product']['Description'];
        }

        if(!$is_new){
            // Product already exists
            $post_id = $results[0]->ID;
            Aralco_WooCommerce_Connector::log_info("Updating Product ID " . $item['ProductID'] . " - " .
                $item['Product']['Code'] . " - " . $item['Product']['Name']);
        } else {
            // Product is new
            $post_id = wp_insert_post(array(
                'post_type'         => 'product',
                'post_status'       => $post_type,
                'post_title'        => $item['Product']['Name'],
                'post_content'      => $item['Product']['Description'],
                'post_excerpt'      => $item['Product']['SeoDescription']
            ), true);
            Aralco_WooCommerce_Connector::log_info("Creating Product ID " . $item['ProductID'] . " - " .
                $item['Product']['Code'] . " - " . $item['Product']['Name']);
            if($post_id instanceof WP_Error){
                return $post_id;
            }
        }

        update_post_meta($post_id, '_sku', $item['Product']['Code']);
        update_post_meta($post_id, '_visibility', 'visible');
        update_post_meta($post_id, '_aralco_id', $item['ProductID']);
        update_post_meta($post_id, '_aralco_taxes', $item['Product']['Taxes']);

        $price = $item['Product']['Price'];
        $discount_price = isset($item['Product']['DiscountPrice']) ? $item['Product']['DiscountPrice'] : $item['Product']['Price'];

        foreach (array('SellBy', 'RetailBy') as $sell_or_retail_by) {
            if (is_array($item['Product'][$sell_or_retail_by]) && !empty($item['Product'][$sell_or_retail_by]['Code'])) {
                if ($sell_or_retail_by == 'RetailBy' &&
                    $item['Product']['SellBy']['Code'] == $item['Product']['RetailBy']['Code']) continue;
                $sell_or_retail_by_data = array(
                    'code' => $item['Product'][$sell_or_retail_by]['Code'],
                    'multi' => !empty($item['Product'][$sell_or_retail_by]['Multiplicator'])? $item['Product'][$sell_or_retail_by]['Multiplicator'] : 1,
                    'decimals' => !empty($item['Product']['QtyDecimals'])? $item['Product']['QtyDecimals'] : 0,
                );
                if ($sell_or_retail_by_data['multi'] > 1) {
                    if($sell_or_retail_by == 'SellBy') {
                        $price = $price * $sell_or_retail_by_data['multi'];
                        $discount_price = $discount_price * $sell_or_retail_by_data['multi'];
                    } else {
                        $sell_or_retail_by_data['price'] = $item['Product']['Price'] * $sell_or_retail_by_data['multi'];
                        $sell_or_retail_by_data['discount_price'] = (isset($item['Product']['DiscountPrice']) ?
                            $item['Product']['DiscountPrice'] : $item['Product']['Price']) * $sell_or_retail_by_data['multi'];
                    }
                }

                $meta_key = ($sell_or_retail_by == 'SellBy')? '_aralco_sell_by' : '_aralco_retail_by';
                update_post_meta($post_id, $meta_key, $sell_or_retail_by_data);
            }
        }

        if(is_array($item['Product']['CustomerGroupPrices']) && count($item['Product']['CustomerGroupPrices']) > 0){
            update_post_meta($post_id, '_group_prices', $item['Product']['CustomerGroupPrices']);
        }

        $has_dim = $item['Product']['HasDimension'];

        if($has_dim){
            wp_set_object_terms($post_id, 'variable', 'product_type', true);
            $result = Aralco_Processing_Helper::process_product_variations($post_id, $item);
            if ($result instanceof WP_Error){
                if($result->get_error_code() == ARALCO_SLUG . '_dimension_not_enabled'){
                    $post = array('ID' => $post_id, 'post_status' => 'draft');
                    wp_update_post($post);
                    $returnVal = $result;
                } else {
                    Aralco_WooCommerce_Connector::log_error("Product dimensions error", $result);
                    return $result;
                }
            } else {
                if(is_array($results) && count($results) > 0 && $results[0]->post_status != $post_type) {
                    $post = array('ID' => $post_id, 'post_status' => $post_type);
                    wp_update_post($post);
                }
                delete_transient('wc_product_children_' . $post_id);
                delete_transient('wc_var_prices_' . $post_id);
            }
        } else if (!$is_new && $results[0]->post_status != $post_type) {
            $post = array('ID' => $post_id, 'post_status' => $post_type);
            wp_update_post($post);
        }

        /**
         * @var $product false|null|WC_Product_Simple|WC_Product_Variable
         */
        $product = wc_get_product($post_id);

        if($is_new){
            try{
                $product->set_catalog_visibility('visible');
            } catch(Exception $e) {
                Aralco_WooCommerce_Connector::log_error("Product catalog visibility error", $e);
            }
            if($options !== false && isset($options[ARALCO_SLUG . '_field_allow_backorders']) && $options[ARALCO_SLUG . '_field_allow_backorders'] == '1'){
                $product->set_backorders('notify');
                $product->set_stock_status('onbackorder');
            } else {
                $product->set_backorders('no');
                $product->set_stock_status('outofstock');
            }
            $product->set_total_sales(0);
            $product->set_downloadable(false);
            $product->set_virtual(false);
//            if($has_dim) {
                $product->set_manage_stock(true);
//            } else {
//                $product->set_manage_stock(false);
//            }
        }

        $product->set_name($item['Product']['Name']);
        $product->set_description($item['Product']['Description']);
        $product->set_short_description($item['Product']['SeoDescription']);
        if(!$has_dim) {
            $product->set_regular_price($price);
            $product->set_sale_price($discount_price);
            $product->set_price($discount_price);
            if(isset($item['Product']['PromotionExpiry'])) {
                $product->set_date_on_sale_to($item['Product']['PromotionExpiry']);
            }
        }
        $product->set_featured(($item['Product']['Featured'] == true));
        if(isset($item['Product']['WebProperties']['Weight'])){
            $product->set_weight($item['Product']['WebProperties']['Weight']);
        }
        if(isset($item['Product']['WebProperties']['Length'])){
            $product->set_length($item['Product']['WebProperties']['Length']);
        }
        if(isset($item['Product']['WebProperties']['Width'])){
            $product->set_width($item['Product']['WebProperties']['Width']);
        }
        if(isset($item['Product']['WebProperties']['Height'])){
            $product->set_height($item['Product']['WebProperties']['Height']);
        }
//        try{
//            $product->set_sku($item['Product']['Code']);
//        } catch(Exception $e) {}
//        update_post_meta($post_id, '_product_attributes', array());
//        update_post_meta($post_id, '_sale_price_dates_from', '');
//        update_post_meta($post_id, '_sale_price_dates_to', '');
//        update_post_meta($post_id, '_sold_individually', '');
//        wc_update_product_stock($post_id, $single['qty'], 'set');
//        update_post_meta( $post_id, '_stock', $single['qty'] );

        $slug = 'department-' . $item['Product']['DepartmentID'];
        $term = get_term_by( 'slug', $slug, 'product_cat' );
        if($term instanceof WP_Term){
            $product->set_category_ids(array($term->term_id));
        } else {
            $term = get_term_by( 'slug', 'uncategorized', 'product_cat' );
            if($term instanceof WP_Term) {
                $product->set_category_ids(array($term->term_id));
            }
        }

        $product->save();

        // Set Advanced Product Attributes
        try {
            $product_attributes = get_post_meta($post_id, '_product_attributes', true);
            if(!is_array($product_attributes)) $product_attributes = array();
            $product_attributes[wc_attribute_taxonomy_name('aralco-flags')] = array(
                'name' => wc_attribute_taxonomy_name('aralco-flags'),
                'value' => '',
                'position' => 0,
                'is_visible' => '0',
                'is_variation' => '0',
                'is_taxonomy' => '1'
            );

            $terms = array();
            if (isset($item['Product']['New']) && $item['Product']['New'])
                array_push($terms, 'New');
            if (isset($item['Product']['WebClearance']) && $item['Product']['WebClearance'])
                array_push($terms, 'Clearance');
            if (isset($item['Product']['WebSpecial']) && $item['Product']['WebSpecial'])
                array_push($terms, 'Special');
            if (isset($item['Product']['CatalogueOnly']) && $item['Product']['CatalogueOnly'])
                array_push($terms, 'Catalogue Only');

            wp_set_object_terms($post_id, $terms, wc_attribute_taxonomy_name('aralco-flags'));
            update_post_meta($post_id, '_product_attributes', $product_attributes);

        } catch (Exception $exception) {
            Aralco_WooCommerce_Connector::log_error("Product advanced attributes soft error", $exception);
        }
        try {
            global $temp_supplier_mapping;
            if(isset($temp_supplier_mapping)) {
                $suppliers = array();
                foreach ($item['Product']['SupplierIDs'] as $supplier_id) {
                    if (isset($temp_supplier_mapping[$supplier_id])) {
                        array_push($suppliers, $temp_supplier_mapping[$supplier_id]);
                    }
                }

                $product_attributes = get_post_meta($post_id, '_product_attributes', true);
                if(!is_array($product_attributes)) $product_attributes = array();
                $product_attributes[wc_attribute_taxonomy_name('suppliers')] = array(
                    'name' => wc_attribute_taxonomy_name('suppliers'),
                    'value' => '',
                    'position' => 0,
                    'is_visible' => '1',
                    'is_variation' => '0',
                    'is_taxonomy' => '1'
                );
                wp_set_object_terms($post_id, $suppliers, wc_attribute_taxonomy_name('suppliers'));
                update_post_meta($post_id, '_product_attributes', $product_attributes);
            }
        } catch (Exception $exception) {
            Aralco_WooCommerce_Connector::log_error("Product supplier soft error", $exception);
        }

        Aralco_Processing_Helper::process_product_grouping($post_id, $item);
        Aralco_Processing_Helper::process_item_images($post_id, $item);
        return $returnVal;
    }

    static function process_product_grouping($post_id, $aralco_product) {
        $product_attributes = get_post_meta($post_id, '_product_attributes', true);
        $invalid_grouping = array();
        if (!isset($aralco_product['Product']['ProductGrouping'])) return true; // Nothing to do.
        foreach ($aralco_product['Product']['ProductGrouping'] as $i => $group) {
            $group_id = Aralco_Util::sanitize_name($group['Group']);
            $terms_temp = get_terms([
                'taxonomy' => wc_attribute_taxonomy_name('grouping-' . $group_id),
                'hide_empty' => false,
            ]);
            if ($terms_temp instanceof WP_Error || is_numeric($terms_temp)) {
                array_push($invalid_grouping, 'grouping ' . $group_id);
                continue;
            }

            if(count($invalid_grouping) > 0) continue;

            $product_attributes[wc_attribute_taxonomy_name('grouping-' . $group_id)] = array(
                'name' => wc_attribute_taxonomy_name('grouping-' . $group_id),
                'value' => '',
                'position' => $i,
                'is_visible' => '1',
                'is_variation' => '0',
                'is_taxonomy' => '1'
            );
            wp_set_object_terms($post_id, array($group['Value']), wc_attribute_taxonomy_name('grouping-' . $group_id));
        }
        if(count($invalid_grouping) > 0){
            $to_return = new WP_Error(ARALCO_SLUG . '_dimension_not_enabled',
                $aralco_product['Product']['Code'] .
                ' - requires the following groups that are not enabled for ecommerce: ' . implode(', ', $invalid_grouping));
            Aralco_WooCommerce_Connector::log_error("Product grouping error", $terms_temp);
            return $to_return;
        }
        if(count($product_attributes) > 0){
            update_post_meta($post_id, '_product_attributes', $product_attributes);
        }
        return true;
    }

    static function process_item_images($post_id, $item){
        Aralco_Util::delete_all_attachments_for_post($post_id); // Removes all previously attached images
        delete_post_thumbnail($post_id); // Removes the thumbnail/featured image
        update_post_meta($post_id,'_product_image_gallery',''); // Removes the product gallery

        $images = Aralco_Connection_Helper::getImagesForProduct($item['ProductID'], $item['Product']['HasDimension']);
        if($images instanceof WP_Error) {
            Aralco_WooCommerce_Connector::log_error("Product image fetch failed", $images);
            return;
        }
        $upload_dir = wp_upload_dir();

        foreach($images as $key => $image) {
            Aralco_WooCommerce_Connector::log_info("Adding Image " . $key);
            $type = '.jpg';
            if (strpos($image->mime_type, 'png') !== false) {
                $type = '.png';
            } else if (strpos($image->mime_type, 'gif') !== false) {
                $type = '.gif';
            }
            $image_name = 'product-' . $item['ProductID'] . $type;

            $unique_file_name = wp_unique_filename($upload_dir['path'], $image_name); // Generate unique name
            $filename = basename($unique_file_name); // Create image file name
            // Check folder permission and define file location
            if( wp_mkdir_p( $upload_dir['path'] ) ) {
                $file = $upload_dir['path'] . '/' . $filename;
            } else {
                $file = $upload_dir['basedir'] . '/' . $filename;
            }
            // Create the image file on the server
            file_put_contents($file, $image->image_data);
            // Check image file type
            $wp_filetype = wp_check_filetype($filename);
            // Set attachment data
            $attachment = array(
                'post_mime_type' => $wp_filetype['type'],
                'post_title' => sanitize_file_name($filename),
                'post_content' => '',
                'post_status' => 'inherit'
            );
            // Create the attachment
            $attach_id = wp_insert_attachment( $attachment, $file, $post_id );
            $images[$key]->image_id = $attach_id;
            // Include image.php
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            // Define attachment metadata
            $attach_data = wp_generate_attachment_metadata( $attach_id, $file );

            // Assign metadata to attachment
            wp_update_attachment_metadata( $attach_id, $attach_data );
            // asign to feature image
            if($key == 0) {
                // And finally assign featured image to post
                set_post_thumbnail( $post_id, $attach_id );
            }
            // assign to the product gallery
            if($key > 0) {
                // Add gallery image to product
                $attach_id_array = get_post_meta($post_id,'_product_image_gallery', true);
                $attach_id_array .= ','.$attach_id;
                update_post_meta($post_id,'_product_image_gallery',$attach_id_array);
            }
            if(!$item['Product']['HasDimension']) continue; // Nothing left to do

            if($image->barcode < 0 || $image->image_id < 0) continue;

            $args = array(
                'posts_per_page'    => 1,
                'post_type'         => 'product_variation',
                'meta_key'          => '_aralco_barcode',
                'meta_value'        => $image->barcode
            );

            $results = (new WP_Query($args))->get_posts();

            if (count($results) <= 0) continue;

            set_post_thumbnail($results[0]->ID, $image->image_id);
        }
    }

    /**
     * Creates all product variations for a defined variable product.
     *
     * Based on https://stackoverflow.com/questions/47518280
     *
     * @param int $post_id The product id
     * @param array $aralco_product The data from aralco about the product
     * @return bool|WP_Error
     */
    static function process_product_variations($post_id, $aralco_product){
        // Build and register attributes
        $product_attributes = array();
        $invalid_grids = array();
        $options = get_option(ARALCO_SLUG . '_options');
        for ($i = 1; $i < 5; $i++) {
            $dim_id = $aralco_product['Product']['DimensionId' . $i];
            if (isset($dim_id) && !empty($dim_id)) {
                $terms_temp = get_terms([
                    'taxonomy' => wc_attribute_taxonomy_name('grid-' . $dim_id),
                    'hide_empty' => false,
                ]);
                if ($terms_temp instanceof WP_Error || is_numeric($terms_temp)) {
                    array_push($invalid_grids, 'dimension' . $dim_id);
                    continue;
                }

                if(count($invalid_grids) > 0) continue;
                if (count($terms_temp) <= 0) continue;

                $terms = array();
                foreach ($terms_temp as $term_temp){
                    array_push($terms, $term_temp->name);
                }
                $product_attributes[wc_attribute_taxonomy_name('grid-' . $dim_id)] = array(
                    'name' => wc_attribute_taxonomy_name('grid-' . $dim_id),
                    'value' => '',
                    'position' => $i,
                    'is_visible' => '1',
                    'is_variation' => '1',
                    'is_taxonomy' => '1'
                );
                if(count($terms) > 0) {
                    wp_set_object_terms($post_id, $terms, wc_attribute_taxonomy_name('grid-' . $dim_id));
                }
            }
        }
        if(count($invalid_grids) > 0){
            return new WP_Error(ARALCO_SLUG . '_dimension_not_enabled',
                $aralco_product['Product']['Code'] .
                ' - requires the following grids that are not enabled for ecommerce: ' . implode(', ', $invalid_grids));
        }
        if(count($product_attributes) > 0){
            update_post_meta($post_id, '_product_attributes', $product_attributes);
        } else {
            return true; // Nothing to do. Product has no valid attributes.
        }


        // Get the product variation/grids that were created in Aralco for this product
        $combos = Aralco_Connection_Helper::getProductBarcodes($aralco_product['ProductID']);
        if ($combos instanceof WP_Error) return $combos;

        // Loop through and create each product variation
        foreach ($combos as $combo){
            $attributes = array();
            foreach ($combo['Grids'] as $i => $grid){
                if(isset($grid['GridID']) && !empty($grid['GridID'])){
                    $attributes[wc_attribute_taxonomy_name('grid-' . $aralco_product['Product']['DimensionId' . ($i + 1)])] = $grid['GridValue'];
                }
            }

            $uid = (string) $aralco_product['ProductID'] . (string) $combo['Grids'][0]['GridID'] .
                (string) $combo['Grids'][1]['GridID'] . (string) $combo['Grids'][2]['GridID'] .
                (string) $combo['Grids'][3]['GridID'];

            $args = array(
                'posts_per_page'    => 1,
                'post_type'         => 'product_variation',
                'meta_key'          => '_aralco_grid_uid',
                'meta_value'        => $uid
            );

            $results = (new WP_Query($args))->get_posts();

            $new = count($results) <= 0;

            if($new) {
                // Get the Variable product object (parent)
                $product = wc_get_product($post_id);

                $variation_post = array(
                    'post_title' => $product->get_name(),
                    'post_name' => 'product-' . $post_id . '-variation',
                    'post_status' => 'publish',
                    'post_parent' => $post_id,
                    'post_type' => 'product_variation',
                    'guid' => $product->get_permalink()
                );

                // Creating the product variation
                $variation_id = wp_insert_post($variation_post);

                $_aralco_grids = array();
                for($i = 1; $i < 5; $i++){
                    if(isset($aralco_product['Product']['DimensionId' . $i]) &&
                        !empty($aralco_product['Product']['DimensionId' . $i])){
                        $_aralco_grids['dimensionId' . $i] = $aralco_product['Product']['DimensionId' . $i];
                        $_aralco_grids['gridId' . $i] = $combo['Grids'][$i - 1]['GridID'];
                    }
                }
                update_post_meta($variation_id, '_aralco_grids', $_aralco_grids);

                update_post_meta($variation_id, '_aralco_grid_uid', $uid);
            } else {
                $variation_id = $results[0]->ID;
            }

            update_post_meta($variation_id, '_aralco_barcode', $combo['Barcode']);

            // Get an instance of the WC_Product_Variation object
            $variation = new WC_Product_Variation($variation_id);

            // Iterating through the variations attributes
            foreach ($attributes as $taxonomy => $term_name){
                if(!taxonomy_exists($taxonomy)){
                    return new WP_Error(ARALCO_SLUG . '_taxonomy_missing', 'The requested taxonomy "' .
                        $taxonomy . '" does not exist.');
                }

                // Check if the Term name exist.
                if(!term_exists($term_name, $taxonomy)) {
                    return new WP_Error(ARALCO_SLUG . '_term_missing', 'The requested taxonomy term "' .
                        $term_name . '" for taxonomy "' . $taxonomy . '" does not exist.');
                }

                $term_slug = get_term_by('name', $term_name, $taxonomy)->slug; // Get the term slug

                // Get the post Terms names from the parent variable product.
                $post_term_names = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'names'));

                // Check if the post term exist and if not we set it in the parent variable product.
                if(!in_array($term_name, $post_term_names))
                    wp_set_post_terms($post_id, $term_name, $taxonomy, true);

                // Set/save the attribute data in the product variation
                update_post_meta($variation_id, 'attribute_'.$taxonomy, $term_slug);
            }

            ## Set/save all other data

            // SKU
            try {
                $variation->set_sku($aralco_product['Product']['Code']);
            } catch (Exception $e) {
                /* Ignored */
            }

            // Prices
            if(isset($aralco_product['Product']['DiscountPrice'])) {
                $variation->set_price($aralco_product['Product']['Price']);
            } else {
                $variation->set_price($aralco_product['Product']['DiscountPrice']);
                $variation->set_sale_price($aralco_product['Product']['DiscountPrice']);
                if(isset($item['Product']['PromotionExpiry'])) {
                    $variation->set_date_on_sale_to($item['Product']['PromotionExpiry']);
                }
            }
            $variation->set_regular_price($aralco_product['Product']['Price']);

            // Stock
            if($new) {
                if($options !== false && isset($options[ARALCO_SLUG . '_field_allow_backorders']) && $options[ARALCO_SLUG . '_field_allow_backorders'] == '1'){
                    $variation->set_backorders('notify');
                    $variation->set_stock_status('onbackorder');
                } else {
                    $variation->set_backorders('no');
                    $variation->set_stock_status('outofstock');
                }
                $variation->set_manage_stock(true);
            }

            if(isset($aralco_product['Product']['WebProperties']['Weight'])){
                $variation->set_weight($aralco_product['Product']['WebProperties']['Weight']);
            }
            if(isset($aralco_product['Product']['WebProperties']['Length'])){
                $variation->set_length($aralco_product['Product']['WebProperties']['Length']);
            }
            if(isset($aralco_product['Product']['WebProperties']['Width'])){
                $variation->set_width($aralco_product['Product']['WebProperties']['Width']);
            }
            if(isset($aralco_product['Product']['WebProperties']['Height'])){
                $variation->set_height($aralco_product['Product']['WebProperties']['Height']);
            }

            $variation->save(); // Save the data
        }
//        // DEBUG INFO
//        $post = get_post(52594);
//        $post->post_content = '<pre>' . json_encode(array(
//            'time' => (new DateTime('now'))->format('Y-m-d H:i:s'),
//            'input' => $prod_attributes,
//            'output' => $data
//        ), JSON_PRETTY_PRINT) . '</pre>';
//        wp_update_post($post);
        return true;
    }

    /**
     * Syncs the product inventory
     *
     * @param null|WC_Product[] $products the products to update. if null, all products based on last sync date
     * @param bool $everything if to ignore
     * @param null $inventory
     * @return bool|WP_Error true if the inventory sync succeeded, otherwise an instance of WP_Error
     */
    static function sync_stock($products = null, $everything = false, $inventory = null){
        Aralco_WooCommerce_Connector::log_info("Syncing stock");
        $chunked = !is_null($inventory);
        if(!$chunked) {
            if (is_array($products) && count($products) > 0) {
                $inventory = Aralco_Connection_Helper::getProductStockByIDs($products);
            } else {
                if ($everything) set_time_limit(0);
                $lastSync = get_option(ARALCO_SLUG . '_last_sync');
                if (!isset($lastSync) || $lastSync === false || $everything) {
                    $lastSync = date("Y-m-d\TH:i:s", mktime(0, 0, 0, 1, 1, 1900));
                }

                $server_time = Aralco_Connection_Helper::getServerTime();
                if ($server_time instanceof WP_Error) {
                    Aralco_WooCommerce_Connector::log_error("Getting server time failed", $server_time);
                    return $server_time;
                } else if (is_array($server_time) && isset($server_time['UtcOffset'])) {
                    $server_time['UtcOffset'] -= 60; // Adds an extra hour to the sync to adjust for server de-syncs
                    $sign = ($server_time['UtcOffset'] > 0)? '+' : '-';
                    if ($server_time['UtcOffset'] < 0) {
                        $server_time['UtcOffset'] = $server_time['UtcOffset'] * -1;
                    }
                    $temp = DateTime::createFromFormat('Y-m-d\TH:i:s', $lastSync);
                    $temp->modify($sign . $server_time['UtcOffset'] . ' minutes');
                    $lastSync = $temp->format('Y-m-d\TH:i:s');
                }
                $inventory = Aralco_Connection_Helper::getProductStock($lastSync);
            }
            if ($inventory instanceof WP_Error) {
                Aralco_WooCommerce_Connector::log_error("Getting inventory failed", $inventory);
                return $inventory;
            }
        }

        $count = 0;

        $serialInventory = array();

        $options = get_option(ARALCO_SLUG . '_options');
        $backorders = ($options !== false &&
            isset($options[ARALCO_SLUG . '_field_allow_backorders']) &&
            $options[ARALCO_SLUG . '_field_allow_backorders'] == '1') ?
            'notify' : 'no';
        $backorder_stock_status = ($backorders == 'no')? 'outofstock' : 'onbackorder';

        foreach ($inventory as $index => $item){
            $options = get_option(ARALCO_SLUG . '_options');
            if($item['StoreID'] != $options[ARALCO_SLUG . '_field_store_id_stock_from']) continue;

            $args = array(
                'posts_per_page' => 1,
                'post_type'      => 'product',
                'meta_key'       => '_aralco_id',
                'meta_value'     => strval($item['ProductID'])
            );

            $results = (new WP_Query($args))->get_posts();
            if(count($results) <= 0) {
                Aralco_WooCommerce_Connector::log_error("Product ID " . $item['ProductID'] . " not found, skipping!");
                continue; // Product not found. Abort
            }
            $product_id = $results[0]->ID;
            Aralco_WooCommerce_Connector::log_info("Updating stock for " . $results[0]->post_title);

            if($item['GridID1'] != 0 || $item['GridID2'] != 0 || $item['GridID3'] != 0 || $item['GridID4'] != 0){
                $variations = get_posts(array(
                    'numberposts' => -1,
                    'post_type' => 'product_variation',
                    'post_parent' => $product_id,
                ));
                if(count($variations) <= 0) {
                    Aralco_WooCommerce_Connector::log_error("Product should have grids, but doesn't? skipping!");
                    continue; // Nothing to do.
                }

                $found = false;
                foreach ($variations as $variation){
                    $grids = get_post_meta($variation->ID, '_aralco_grids', true);
                    if(!is_array($grids) || count($grids) <= 0) continue; // Nothing to do.
                    if(
                        isset($grids['gridId1']) && (string) $grids['gridId1'] == (string) $item['GridID1'] &&
                        (!isset($grids['gridId2']) || (string) $grids['gridId2'] == (string) $item['GridID2']) &&
                        (!isset($grids['gridId3']) || (string) $grids['gridId3'] == (string) $item['GridID3']) &&
                        (!isset($grids['gridId4']) || (string) $grids['gridId4'] == (string) $item['GridID4'])
                    ){
                        $product_id = $variation->ID;
                        Aralco_WooCommerce_Connector::log_info("Grid " .
                            $item['GridID1'] . ":" . $item['GridID2'] . ":" . $item['GridID3'] . ":" . $item['GridID4'] .
                            ", postId " . $variation->ID);
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    Aralco_WooCommerce_Connector::log_error("Product ID " . $item['ProductID'] . " grid " .
                        $item['GridID1'] . ":" . $item['GridID2'] . ":" . $item['GridID3'] . ":" . $item['GridID4'] .
                        "not found, skipping!");
                    continue; // Nothing to do.
                }
            }

            if(!empty($item['SerialNumber'])){
                if (!isset($serialInventory[$item['ProductID']])){
                    $serialInventory[$item['ProductID']] = 0;
                }
                if ($item['Available'] > 0) $serialInventory[$item['ProductID']]++;
                continue; //We will deal with those items later
            }

            $available = $item['Available'];

            // Upscale inventory if decimal unit
            $sell_by = get_post_meta($product_id, '_aralco_sell_by', true);
            if(is_array($sell_by) && is_numeric($sell_by['decimals']) && $sell_by['decimals'] > 0){
                $available = $available * (10 ** $sell_by['decimals']);
            }

            update_post_meta($product_id, '_manage_stock', 'yes');
            update_post_meta($product_id, '_backorders', $backorders);
            update_post_meta($product_id, '_stock', $available);
            update_post_meta($product_id, '_stock_status', ($available >= 1) ? 'instock' : $backorder_stock_status);
            $count++;
        }

        foreach ($serialInventory as $index => $item){
            $args = array(
                'posts_per_page' => 1,
                'post_type'      => 'product',
                'meta_key'       => '_aralco_id',
                'meta_value'     => strval($index)
            );
            $results = (new WP_Query($args))->get_posts();
            $product_id = $results[0]->ID;

            update_post_meta($product_id, '_manage_stock', 'yes');
            update_post_meta($product_id, '_backorders', $backorders);
            update_post_meta($product_id, '_stock', $item);
            update_post_meta($product_id, '_stock_status', ($item >= 1) ? 'instock' : $backorder_stock_status);
            $count++;
        }
        Aralco_WooCommerce_Connector::log_info("Done syncing inventory");
        return true;
    }

    /**
     * Downloads and registers all the grids
     *
     * @param null $raw_grids
     * @return true|WP_Error True if everything works, or WP_Error instance if something goes wrong
     */
    static function sync_grids($raw_grids = null){
        $chunked = !is_null($raw_grids);
        if(!$chunked) {
            Aralco_WooCommerce_Connector::log_info("Syncing all grids");
            // Get the grids.
            $raw_grids = Aralco_Connection_Helper::getGrids();
            if($raw_grids instanceof WP_Error || (isset($raw_grids[0]) && !isset($raw_grids[0]['CategoryId']))) {
                Aralco_WooCommerce_Connector::log_error("Fetching grids failed!", $raw_grids);
                return $raw_grids; // Something isn't right. Probably API error
            }
        } else {
            Aralco_WooCommerce_Connector::log_info("Syncing grids (chunked)");
        }

//        if(!isset($raw_grids[0])){
//            return true; // Nothing to do;
//        }

        // Clean up the grids so we can loop cleaner
        $grids = array();
        foreach($raw_grids as $key => $grid){
            // We are going to nest all the grid values instead of having a flat list of name/value pairs
            if(!isset($grids[$grid['CategoryId']])){
                $grids[$grid['CategoryId']] = array();
                $grids[$grid['CategoryId']]['CategoryId'] = $grid['CategoryId'];
                $grids[$grid['CategoryId']]['CategoryName'] = $grid['CategoryName'];
                $grids[$grid['CategoryId']]['values'] = array();
            }
            $grids[$grid['CategoryId']]['values'][$grid['ValueId']] = array(
                'ValueId' => $grid['ValueId'],
                'ValueName' => $grid['ValueName']
            );
        }
        unset($raw_grids);

        // Start data entry
        $i1 = 0;
        foreach($grids as $key => $grid){
            // Part 1: The top level grid groupings
            $does_exist = taxonomy_exists(wc_attribute_taxonomy_name('grid-' . $grid['CategoryId']));
            if($does_exist) {
                $id = wc_attribute_taxonomy_id_by_name('grid-' . $grid['CategoryId']);
                wc_update_attribute($id, array(
                    'id' => $id,
                    'name' => $grid['CategoryName'],
                    'slug' => 'grid-' . $grid['CategoryId'],
                    'type' => 'select',
                    'order_by' => 'menu_order',
                    'has_archives' => false
                ));
                Aralco_WooCommerce_Connector::log_info("Updating grid " . $grid['CategoryName'] . " slug grid-" . $grid['CategoryId']);
            } else {
                wc_create_attribute(array(
                    'name' => $grid['CategoryName'],
                    'slug' => 'grid-' . $grid['CategoryId'],
                    'type' => 'select',
                    'order_by' => 'menu_order',
                    'has_archives' => false
                ));
                Aralco_WooCommerce_Connector::log_info("Creating grid " . $grid['CategoryName'] . " slug grid-" . $grid['CategoryId']);
            }
            // Part 2: Dealing with the values
            $i2 = 0;
            foreach($grid['values'] as $k => $value) {
                $taxonomy = wc_attribute_taxonomy_name('grid-' . $grid['CategoryId']);
                $slug = sprintf('%s-val-%s', $taxonomy, '' . $value['ValueId']);
                $existing = get_term_by('slug', $slug, $taxonomy);
                if ($existing == false){
                    $result = wp_insert_term($value['ValueName'], $taxonomy, array(
                        'slug' => $slug
                    ));
                    if($result instanceof WP_Error){
//                        return $result;
                        // Ignore and continue for now. //TODO
                        Aralco_WooCommerce_Connector::log_error("Grid doesn't seem to exist. This is unfortunately normal and should succeed on the next sync.", $result);
                        continue;
                    }
                    $id = $result['term_id'];
                    Aralco_WooCommerce_Connector::log_info("Created value " . $value['ValueName'] . " in " . $taxonomy);
                } else {
                    $id = $existing->term_id;
                    wp_update_term($id, $taxonomy, array(
                        'name' => $value['ValueName'],
                    ));
                    Aralco_WooCommerce_Connector::log_info("Updated value " . $value['ValueName'] . " in " . $taxonomy);
                }
                delete_term_meta($id, 'order');
                add_term_meta($id, 'order', $i2++);
                $temp_key = 'order_' . wc_attribute_taxonomy_name('grid-' . $grid['CategoryId']);
                delete_term_meta($id, $temp_key);
                add_term_meta($id, $temp_key, $i1);
                delete_term_meta($id, 'aralco_grid_id');
                add_term_meta($id, 'aralco_grid_id', $grid['CategoryId']);
            }
            $i1++;
        }
        Aralco_WooCommerce_Connector::log_info("Done syncing grids");
        return true;
    }

    /**
     * @param null|array $departments
     * @return true|WP_Error
     */
    static function sync_departments($departments = null){
        if (is_array($departments)) {
            Aralco_WooCommerce_Connector::log_info("Syncing departments: " . json_encode($departments));
        } else {
            Aralco_WooCommerce_Connector::log_info("Syncing all departments");
        }
        set_time_limit(3600);
        $chunked = !is_null($departments);
        if(!$chunked) {
            $departments = Aralco_Connection_Helper::getDepartments();
            if($departments instanceof WP_Error) {
                Aralco_WooCommerce_Connector::log_error("Fetching departments failed!", $departments);
                return $departments;
            }
        }

        $count = 0;
        // Creation Pass (1/2)
        foreach($departments as $department){
            $count++;
            $slug = 'department-' . $department['Id'];
            // See if term exists
            $term = get_term_by( 'slug', $slug, 'product_cat' );
            if($term === false){
                // Doesn't exist so lets create it
                $result = wp_insert_term(
                    $department['Name'],
                    'product_cat',
                    array(
                        'description' => isset($department['Description']) ? $department['Description'] : '',
                        'slug' => $slug
                    ));
                if ($result instanceof WP_Error) {
                    Aralco_WooCommerce_Connector::log_error("Failed to add department " . $department['Name'] . " as " . $slug, $result);
                    return $result;
                }
                Aralco_WooCommerce_Connector::log_info("Added department " . $department['Name'] . " as " . $slug);
                $term_id = $result['term_id'];
            } else {
                // exists so lets update it
                $args = array(
                    'description' => isset($department['Description']) ? $department['Description'] : '',
                    'slug' => $slug
                );
                if(!isset($department['ParentId']) && $department !== 0){
                    $args['parent'] = 0; // to clear parent if parent has been removed
                }
                $result = wp_update_term($term->term_id, 'product_cat', $args);
                if ($result instanceof WP_Error) {
                    Aralco_WooCommerce_Connector::log_error("Failed to update department " . $department['Name'] . " as " . $slug, $result);
                    return $result;
                }
                $term_id = $term->term_id;
                Aralco_WooCommerce_Connector::log_info("Updated department " . $department['Name'] . " as " . $slug . " with new metadata");
            }

            //update group meta with grouping info
            $groups = array();
            if(is_array($department['Filters'])){
                foreach ($department['Filters'] as $grouping) {
                    array_push($groups, $grouping['Name']);
                }
            }
            if(count($groups) > 0){
                update_term_meta($term_id, 'aralco_filters', $groups);
            } else {
                $exists = get_term_meta($term_id, 'aralco_filters', true);
                if($exists != false) delete_term_meta($term_id, 'aralco_filters');
            }
            Aralco_Processing_Helper::process_department_images($term_id, $department['Id']);
        }

        // Relationship Pass (2/2)
        foreach($departments as $department){
            if(!isset($department['ParentId'])) continue; // No parent. Nothing to do.

            $parent_slug = 'department-' . $department['ParentId'];
            $parent_term = get_term_by( 'slug', $parent_slug, 'product_cat' );
            if($parent_term === false) continue; // Parent not enabled for ecommerce or child is orphaned. Either way, nothing to do.

            $child_slug = 'department-' . $department['Id'];
            $child_term = get_term_by( 'slug', $child_slug, 'product_cat' );
            if($child_term === false) continue; // Child somehow doesn't exist. Would never happen but leaving in for sanity

            $result = wp_update_term($child_term->term_id, 'product_cat', array(
                'description' => isset($department['Description']) ? $department['Description'] : '',
                'slug' => $child_slug,
                'parent' => $parent_term->term_id
            ));
            if ($result instanceof WP_Error) {
                Aralco_WooCommerce_Connector::log_error("Failed to updated terms relationship for " . $department['Name'] . " as department-" . $department['Id'], $result);
                return $result;
            }
            Aralco_WooCommerce_Connector::log_info("Updated terms relationship for " . $department['Name'] . " as department-" . $department['Id']);
        }
        Aralco_WooCommerce_Connector::log_info("Done syncing departments");
        return true;
    }

    static function sync_groupings($groupings_raw = null) {
        $chunked = !is_null($groupings_raw);
        if(!$chunked) {
            Aralco_WooCommerce_Connector::log_info("Syncing groupings all");
            $groupings_result = Aralco_Connection_Helper::getGroupings();
            if ($groupings_result instanceof WP_Error) {
                Aralco_WooCommerce_Connector::log_error("Fetching groupings failed!", $groupings_result);
                return $groupings_result;
            }
            $groupings_raw = $groupings_result['data'];
        } else {
            Aralco_WooCommerce_Connector::log_info("Syncing groupings (chunked)");
        }

        $groupings = array();
        foreach ($groupings_raw as $item) {
            if (!empty($item['GroupingID'])){
                if(!isset($groupings[$item['Group']])){
                    $groupings[$item['Group']] = array(
                        'group' => $item['Group'],
                        'groupDescription' => $item['GroupDescription'],
                        'values' => array(
                            $item['Value'] => (isset($item['ValueDescription'])) ? $item['ValueDescription'] : ''
                        ),
                    );
                } else {
                    $groupings[$item['Group']]['values'][$item['Value']] = (isset($item['ValueDescription'])) ? $item['ValueDescription'] : '';
                }
            }
        }

        $groupings = array_values($groupings);

        $count = 0;
        foreach($groupings as $index => $grouping){
            $name = 'grouping-' . Aralco_Util::sanitize_name($grouping['group']);
            $does_exist = taxonomy_exists(wc_attribute_taxonomy_name($name));
            if($does_exist) {
                $id = wc_attribute_taxonomy_id_by_name($name);
                wc_update_attribute($id, array(
                    'name' => $grouping['group'],
                    'slug' => $name
                ));
                Aralco_WooCommerce_Connector::log_info("Updating grouping " . $grouping['group'] . " slug " . $name);
            } else {
                $id = wc_create_attribute(array(
                    'name' => $grouping['group'],
                    'slug' => $name,
                    'type' => 'select',
                    'order_by' => 'menu_order',
                    'has_archives' => false
                ));
                Aralco_WooCommerce_Connector::log_info("Creating grouping " . $grouping['group'] . " slug " . $name);
            }
            if ($id instanceof WP_Error) continue;

            $i2 = 0;
            foreach($grouping['values'] as $key => $value) {
                $taxonomy = wc_attribute_taxonomy_name($name);
                $slug = sprintf('%s-val-%s', $taxonomy, Aralco_Util::sanitize_name($key));
                $existing = get_term_by('slug', $slug, $taxonomy);
                if ($existing == false){
                    $result = wp_insert_term($key, $taxonomy, array(
                        'slug' => $slug,
                        'description' => $value
                    ));
                    if($result instanceof WP_Error){
//                        return $result;
                        // Ignore and continue for now. //TODO
                        Aralco_WooCommerce_Connector::log_error("Grouping doesn't seem to exist. This is unfortunately normal and should succeed on the next sync.", $result);
                        continue;
                    }
                    $id = $result['term_id'];
                    Aralco_WooCommerce_Connector::log_info("Created value " . $key . " in " . $taxonomy);
                } else {
                    $id = $existing->term_id;
                    wp_update_term($id, $taxonomy, array(
                        'name' => $key,
                        'description' => $value,
                    ));
                    Aralco_WooCommerce_Connector::log_info("Updated value " . $key . " in " . $taxonomy);
                }
                delete_term_meta($id, 'order');
                add_term_meta($id, 'order', $i2++);
                delete_term_meta($id, 'order_' . $taxonomy);
                add_term_meta($id, 'order_' . $taxonomy, $count);
            }
            $count++;
        }
        Aralco_WooCommerce_Connector::log_info("Done syncing grouping");
        return true;
    }

    static function sync_suppliers() {
        Aralco_WooCommerce_Connector::log_info("Syncing suppliers");
        $taxonomy = wc_attribute_taxonomy_name('suppliers');
        $does_exist = taxonomy_exists($taxonomy);
        if($does_exist) {

            $suppliers = Aralco_Connection_Helper::getSuppliers();
            $count = 0;
            $supplier_mapping = array();

            foreach ($suppliers as $index => $supplier) {
                $slug = sprintf('%s-val-%s-%d', $taxonomy, Aralco_Util::sanitize_name($supplier['SupplierCode']), $supplier['SupplierID']);
                $existing = get_term_by('slug', $slug, $taxonomy);
                if ($existing == false) {
                    $result = wp_insert_term($supplier['Name'], $taxonomy, array(
                        'slug' => $slug,
                        'description' => ''
                    ));
                    if ($result instanceof WP_Error) {
//                        return $result;
                        Aralco_WooCommerce_Connector::log_error("Failed to create supplier " . $supplier['Name'], $result);
                        continue;
                    }
                    $id = $result['term_id'];
                    Aralco_WooCommerce_Connector::log_info("Created value " . $supplier['Name'] . " in " . $taxonomy);
                } else {
                    $id = $existing->term_id;
                    wp_update_term($id, $taxonomy, array(
                        'name' => $supplier['Name']
                    ));
                    Aralco_WooCommerce_Connector::log_info("Updated value " . $supplier['Name'] . " in " . $taxonomy);
                }
                delete_term_meta($id, 'order');
                add_term_meta($id, 'order', $count);
                delete_term_meta($id, 'order_' . $taxonomy);
                add_term_meta($id, 'order_' . $taxonomy, $count);
                $supplier_mapping[$supplier['SupplierID']] = $supplier['Name'];
                $count++;
            }

            update_option(ARALCO_SLUG . '_supplier_mapping', $supplier_mapping);
        }
        Aralco_WooCommerce_Connector::log_info("Done syncing suppliers");
        return true;
    }

    static function process_department_images($term_id, $department_id){
        $existing = get_term_meta($term_id, 'thumbnail_id', true);
        if(!empty($existing)){
            wp_delete_attachment($existing, true);
            delete_term_meta($term_id, 'thumbnail_id');
            Aralco_WooCommerce_Connector::log_info("Deleted department image");
        }

        $image = Aralco_Connection_Helper::getImageForDepartment($department_id);
        if(!$image instanceof Aralco_Image){
            Aralco_WooCommerce_Connector::log_info("No department image to add");
            return; // Nothing to do.
        }
        $upload_dir = wp_upload_dir();

        $type = '.jpg';
        if (strpos($image->mime_type, 'png') !== false) {
            $type = '.png';
        } else if (strpos($image->mime_type, 'gif') !== false) {
            $type = '.gif';
        }
        $image_name = 'department-' . $department_id . $type;

        $unique_file_name = wp_unique_filename($upload_dir['path'], $image_name); // Generate unique name
        $filename = basename($unique_file_name); // Create image file name
        // Check folder permission and define file location
        if( wp_mkdir_p( $upload_dir['path'] ) ) {
            $file = $upload_dir['path'] . '/' . $filename;
        } else {
            $file = $upload_dir['basedir'] . '/' . $filename;
        }
        // Create the image file on the server
        file_put_contents($file, $image->image_data);
        // Check image file type
        $wp_filetype = wp_check_filetype( $filename, null );
        // Set attachment data
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => sanitize_file_name( $filename ),
            'post_content' => '',
            'post_status' => 'publish'
        );
        // Create the attachment
        $attach_id = wp_insert_attachment( $attachment, $file );
        // Include image.php
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        // Define attachment metadata
        $attach_data = wp_generate_attachment_metadata( $attach_id, $file );

        // Assign metadata to attachment
        wp_update_attachment_metadata( $attach_id, $attach_data );
        // asign to feature image
        update_term_meta($term_id,'thumbnail_id', $attach_id);
        Aralco_WooCommerce_Connector::log_info("Added department image");
    }

    /**
     * Registers new user as customer in Aralco
     *
     * @param int $user_id the wordpress user's id
     * @return int|WP_Error the corresponding id in Aralco for the wordpress user
     */
    static function process_new_customer($user_id){
        $user = get_user_by('ID', $user_id);
        // First, check if this user actually exists.
        $data = Aralco_Connection_Helper::getCustomer('UserName', $user->user_email);
        if ($data && !$data instanceof WP_Error) {
            // The user already exist so just return the ID
            return $data['id'];
        }
        // User doesn't exist so let's create it.
        $customer = array(
            'Username'        => $user->user_email,
            'Password'        => 'AralcoWeb', // Required but not used
            'Name'            => (empty($user->first_name)) ? 'Unknown' : $user->first_name,
            'Surname'         => (empty($user->last_name)) ? 'Unknown' : $user->last_name,
            'Companyname'     => 'Web Registration',
            'Address1'        => 'Unknown',
//            'City'            => '',
            'Country'         => 'Unknown',
            'ProvinceState'   => 'Unknown',
//            'Phone'           => ''
        );
        return Aralco_Connection_Helper::createCustomer($customer);
    }

    /**
     * update a customer in Aralco
     *
     * @param int $user_id the wordpress user's id.
     * @param int $aralco_id the corresponding ID in aralco to update.
     * @return bool|WP_Error true if updated, otherwise WP_Error
     * @noinspection PhpMissingParamTypeInspection
     */
    static function process_customer_update($user_id, $aralco_id){
        try {
            $user = new WC_Customer($user_id);
        } catch (Exception $e) {
            return new WP_Error(ARALCO_SLUG . '_customer_update_customer_not_found');
        }
        $aralco_data = get_user_meta($user_id, "aralco_data", true);
        $customer = array(
            'Username'        => $aralco_data['email'],
            'Address1'        => $user->get_billing_address_1() ?? 'Unknown',
            'Address2'        => $user->get_billing_address_2() ?? '',
            'City'            => $user->get_billing_city() ?? '',
            'Companyname'     => $user->get_billing_company() ?? '',
            'Country'         => $user->get_billing_country() ?? 'Unknown',
            'Name'            => $user->get_billing_first_name() ?? 'Unknown',
            'Surname'         => $user->get_billing_last_name() ?? 'Unknown',
            'Phone'           => $user->get_billing_phone() ?? '',
            'ProvinceState'   => $user->get_billing_state() ?? 'Unknown',
            'ZipPostalCode'   => $user->get_billing_postcode() ?? '',
        );
        return Aralco_Connection_Helper::updateCustomer($customer);
    }

    /**
     * update a customer in Aralco from order
     *
     * @param array $aralco_data the aralco user data object
     * @param WC_Order $order the order to pull info from
     * @return bool|WP_Error true if updated, otherwise WP_Error
     * @noinspection PhpMissingParamTypeInspection
     */
    static function process_customer_update_from_order($aralco_data, $order){
        $customer = array(
            'Username'        => $aralco_data['email'],
            'Address1'        => $order->get_billing_address_1() ?? 'Unknown',
            'Address2'        => $order->get_billing_address_2() ?? '',
            'City'            => $order->get_billing_city() ?? '',
            'Companyname'     => $order->get_billing_company() ?? '',
            'Country'         => $order->get_billing_country() ?? 'Unknown',
            'Name'            => $order->get_billing_first_name() ?? 'Unknown',
            'Surname'         => $order->get_billing_last_name() ?? 'Unknown',
            'Phone'           => $order->get_billing_phone() ?? '',
            'ProvinceState'   => $order->get_billing_state() ?? 'Unknown',
            'ZipPostalCode'   => $order->get_billing_postcode() ?? '',
        );
        return Aralco_Connection_Helper::updateCustomer($customer);
    }

    /**
     * Syncs the Customer Groups
     *
     * @return true|WP_Error True if groupings were updated, or WP_Error if a problem occurred.
     */
    static function sync_customer_groups() {
        Aralco_WooCommerce_Connector::log_info("Syncing customer groups");
        $groups = Aralco_Connection_Helper::getCustomerGroups();
        if ($groups instanceof WP_Error) {
            Aralco_WooCommerce_Connector::log_info("Getting customer groups failed", $groups);
            return $groups;
        }

        update_option(ARALCO_SLUG . '_customer_groups', $groups, true);
        Aralco_WooCommerce_Connector::log_info("Done syncing customer groups");
        return true;
    }

    static function sync_taxes() {
        Aralco_WooCommerce_Connector::log_info("Syncing taxes");
        $taxes = Aralco_Connection_Helper::getTaxes();
        if ($taxes instanceof WP_Error) {
            Aralco_WooCommerce_Connector::log_error("Error getting taxes", $taxes);
            return $taxes;
        }

        $shipping_code = get_option(ARALCO_SLUG . '_shipping_product_code');
        if($shipping_code !== false) {
            $tax_shipping = get_post_meta(wc_get_product_id_by_sku($shipping_code), '_aralco_taxes', true);
        }
        if(!isset($tax_shipping) || !is_array($tax_shipping)){
            $tax_shipping = [];
        }

        global $wpdb;
        $existing_mapping = get_option(ARALCO_SLUG . '_tax_mapping', array());
        $tax_mapping = array();
        $orderCounter = 0;
        foreach ($taxes as $tax){
            Aralco_WooCommerce_Connector::log_info("Doing tax " . $tax['name']);
            // '%d', '%f', '%s' (integer, float, string).
            $tax_mapping[$tax['id']] = array();
            $provinces = explode(',', isset($tax['provinceState']) ? $tax['provinceState'] : '');
            foreach ($provinces as $i2 => $province){
                $province = strtoupper(trim($province));
                if (!in_array(strlen($province), array(0, 2))) continue; // Only allow two letter state codes and empty fields

                $id = -1;

                if(isset($existing_mapping[$tax['id']])){
                    if(count($existing_mapping[$tax['id']]) > 0) {
                        $ids = implode(',', $existing_mapping[$tax['id']]);
                        $existing_taxes = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id IN ({$ids})", ARRAY_A);
                        foreach ($existing_taxes as $i => $existing_tax){
                            if($existing_tax['tax_rate_state'] == $province){
                                $id = (int)$existing_tax['tax_rate_id'];
                                unset($existing_mapping[$tax['id']][$i]);
                                $existing_mapping[$tax['id']] = array_values($existing_mapping[$tax['id']]);
                                break;
                            }
                        }
                    }
                }

                $shipping = (in_array($tax['id'], $tax_shipping, true) && !empty($province)) ? 1 : 0;

                if($id >= 0){
                    $result = $wpdb->update($wpdb->prefix . 'woocommerce_tax_rates', array(
                        'tax_rate' => number_format($tax['percentage'], 4, '.', ''),
                        'tax_rate_name' => $tax['name'],
                        'tax_rate_priority' => $tax['ecommerceFederalTax'] == true? 1 : 2,
                        'tax_rate_compound' => 0,
                        'tax_rate_shipping' => $shipping,
                        'tax_rate_order' => $orderCounter++
                    ), array(
                        'tax_rate_id' => $id
                    ));
                    if ($result !== false) {
                        $tax_mapping[$tax['id']][] = $id;
                    }
                } else {
                    $result = $wpdb->insert($wpdb->prefix . 'woocommerce_tax_rates', array(
                        'tax_rate_state' => $province,
                        'tax_rate' => number_format($tax['percentage'], 4, '.', ''),
                        'tax_rate_name' => $tax['name'],
                        'tax_rate_priority' => $tax['ecommerceFederalTax'] == true? 1 : 2,
                        'tax_rate_compound' => 0,
                        'tax_rate_shipping' => $shipping,
                        'tax_rate_order' => $orderCounter++
                    ));
                    if ($result != false) {
                        $tax_mapping[$tax['id']][] = $wpdb->insert_id;
                    }
                }
            }
        }

        $ids_to_remove = [];
        foreach ($existing_mapping as $tax){
            foreach ($tax as $region){
                $ids_to_remove[] = $region;
            }
        }

        if(count($ids_to_remove) > 0) {
            $ids_to_remove = implode(',', $ids_to_remove);
            $wpdb->query("DELETE FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id IN ({$ids_to_remove})");
        }

        update_option(ARALCO_SLUG . '_tax_mapping', $tax_mapping);
        Aralco_WooCommerce_Connector::log_info("Done syncing taxes");
        return true;
    }

    /**
     * Syncs stores
     *
     * @return true|WP_Error True if the stores were updated, or WP_Error if a problem occurred.
     */
    static function sync_stores() {
        Aralco_WooCommerce_Connector::log_info("Syncing stores");
        $stores = Aralco_Connection_Helper::getStores();
        if ($stores instanceof WP_Error) {
            Aralco_WooCommerce_Connector::log_error("Getting stores failed", $stores);
            return $stores;
        }

        update_option(ARALCO_SLUG . '_stores', $stores, true);
        Aralco_WooCommerce_Connector::log_info("Done syncing stores");
        return true;
    }

    static function update_points_exchange() {
        $exchange = Aralco_Connection_Helper::getPointsExchange();
        if($exchange instanceof WP_Error) return;

        update_option(ARALCO_SLUG . '_points_exchange', $exchange);
    }

    /**
     * Processes and submits the order to Aralco
     *
     * @param int $order_id the id of the order to submit to Aralco
     * @param $just_return bool if true, the order will be returned instead of submitted
     * @return bool|array|WP_Error true if the order was submitted with no issue, false if orders are turned off, the
     * order array if $just_return is true and WP_Error instance if something went wrong
     */
    static function process_order($order_id, $just_return = false) {
        $options = get_option(ARALCO_SLUG . '_options');

        if((!isset($options[ARALCO_SLUG . '_field_order_enabled']) || $options[ARALCO_SLUG . '_field_order_enabled'] != true) &&
            $just_return == false) {
            // Do nothing. orders are disabled
            return false;
        }

        $order = wc_get_order($order_id);
        if(!$order) {
            // Return and error if the order dose not exist
            return new WP_Error(
                ARALCO_SLUG . '_message',
                __('No order was found for the requested order ID', ARALCO_SLUG)
            );
        }

        if($order instanceof WC_Order_Refund) {
            // Return and error if the order is refunded. May allow this in the future but some work may be required.
            return new WP_Error(
                ARALCO_SLUG . '_message',
                __('The requested order was already refunded and is not submittable to aralco.', ARALCO_SLUG)
            );
        }

        $aralco_user = null;
        $just_created_aralco_user = false;
        if($order->get_user()){ // If not a guest
            $aralco_user = get_user_meta($order->get_user()->ID, 'aralco_data', true); // Get aralco data from WP DB
            if (!$aralco_user) { // If that failed, attempt to fetch from Aralco directly.
                $aralco_user = Aralco_Connection_Helper::getCustomer('UserName', $order->get_user()->user_email);
            }
        } else { // If user logged out, try retrieving user another way.
            $email = $order->get_billing_email();
            $aralco_user = Aralco_Connection_Helper::getCustomer('UserName', $email);
            if(!$aralco_user || $aralco_user instanceof WP_Error){
                $customer = array(
                    'Username'        => $email,
                    'Password'        => 'AralcoWeb', // Required but not used
                    'Address1'        => $order->get_billing_address_1() ?? 'Unknown',
                    'Address2'        => $order->get_billing_address_2() ?? '',
                    'City'            => $order->get_billing_city() ?? '',
                    'Companyname'     => $order->get_billing_company() ?? '',
                    'Country'         => $order->get_billing_country() ?? 'Unknown',
                    'Name'            => $order->get_billing_first_name() ?? 'Unknown',
                    'Surname'         => $order->get_billing_last_name() ?? 'Unknown',
                    'Phone'           => $order->get_billing_phone() ?? '',
                    'ProvinceState'   => $order->get_billing_state() ?? 'Unknown',
                    'ZipPostalCode'   => $order->get_billing_postcode() ?? '',
                );
                $create_user_result = Aralco_Connection_Helper::createCustomer($customer);
                if($create_user_result instanceof WP_Error){
                    Aralco_WooCommerce_Connector::log_error('Failed to create user for order ' . $order->get_id(), $create_user_result);
                    return new WP_Error(
                        ARALCO_SLUG . '_message',
                        __('Was unable to create or find a user with the email: ' . $email, ARALCO_SLUG)
                    );
                }
                $just_created_aralco_user = true;
                $aralco_user = Aralco_Connection_Helper::getCustomer('UserName', $email);
                if(!$aralco_user || $aralco_user instanceof WP_Error){
                    Aralco_WooCommerce_Connector::log_error('Failed to find newly created user for order ' . $order->get_id(), $aralco_user);
                    return new WP_Error(
                        ARALCO_SLUG . '_message',
                        __('Created a new user with the email ' . $email . ' but could not find them afterwards?', ARALCO_SLUG)
                    );
                }
            }
        }

        if(!$aralco_user || $aralco_user instanceof WP_Error) {
            Aralco_WooCommerce_Connector::log_error('Could not reliably assign a BOS customer to the current order. Aborting! Order Email: ' . $order->get_id(), $aralco_user);
            return new WP_Error(
                ARALCO_SLUG . '_message',
                __('Could not reliably assign a BOS customer to the current order. Please contact us!', ARALCO_SLUG)
            );
        }

        if(!$just_created_aralco_user){ // If we just created the user, the data will be up to date.
            Aralco_Processing_Helper::process_customer_update_from_order($aralco_user, $order);
        }

        if(count($order->get_shipping_methods()) > 0) {
            $shipping_rate = array_values($order->get_shipping_methods())[0];
            $is_local_pickup = $shipping_rate->get_method_id() == ARALCO_SLUG . '_pickup_shipping';
            if($is_local_pickup) {
                $pickup_store_id = array_values($order->get_shipping_methods())[0]->get_meta('aralco_id');
            }
        } else {
            $is_local_pickup = false;
        }

        $is_credit = $order->get_payment_method() == 'aralco_account_credit';
        $is_quote = isset($options[ARALCO_SLUG . '_field_order_is_quote']) && $options[ARALCO_SLUG . '_field_order_is_quote'] == '1';

        $payment = [
            'subTotal'            => strval($order->get_subtotal()),
            'tax'                 => $order->get_total_tax(),
            'shipping'            => $order->get_shipping_total(),
            'total'               => $order->get_total(),
            'status'              => '1'
        ];

        if($is_quote){
            $payment['totalPaid'] = '0.00';
            $payment['totalDue'] = $order->get_total();
        } else {
            $payment['paymentMethod'] = ($is_credit) ? 'Account' : 'CC-' . $options[ARALCO_SLUG . '_field_tender_code'] . '-****************';
            $payment['message'] = $order->get_payment_method_title();
            $payment['totalDue'] = ($order->is_paid()) ? '0.00' : $order->get_total();
            $points_value = $order->get_meta('aralco_points_value', 'true', 'edit');
            if(!empty($points_value)) {
                $payment['totalPaid'] = $order->get_total() - $points_value;
                $payment['PointsPaid'] = $points_value;
            } else {
                $payment['totalPaid'] = ($order->is_paid()) ? $order->get_total() : '0.00';
            }
        }

        if(!$is_credit && !$is_quote){
            $payment['AuthorizationNumber'] = '12345'; // TODO: get real Auth Number
            $payment['ReferenceNumber'] = '1234'; // TODO: get real Ref Number
        }

        $billing_address = array(
            'name'          => $order->get_billing_first_name(),
            'surname'       => $order->get_billing_last_name(),
            'companyName'   => $order->get_billing_company(),
            'address1'      => $order->get_billing_address_1(),
            'address2'      => $order->get_billing_address_2(),
            'city'          => $order->get_billing_city(),
            'provinceState' => $order->get_billing_state(),
            'country'       => $order->get_billing_country(),
            'zipPostalCode' => $order->get_billing_postcode()
        );

        if($order->has_shipping_address()){
            $shipping_address = array(
                'name'          => $order->get_shipping_first_name(),
                'surname'       => $order->get_shipping_last_name(),
                'companyName'   => $order->get_shipping_company(),
                'address1'      => $order->get_shipping_address_1(),
                'address2'      => $order->get_shipping_address_2(),
                'city'          => $order->get_shipping_city(),
                'provinceState' => $order->get_shipping_state(),
                'country'       => $order->get_shipping_country(),
                'zipPostalCode' => $order->get_shipping_postcode()
            );
        } else {
            $shipping_address = $billing_address;
        }

        $aralco_order = array(
            'username'   => $aralco_user['email'], //(isset($aralco_user['email'])) ? $aralco_user['email'] : $options[ARALCO_SLUG . '_field_default_order_email'],
            'storeId'    => $options[ARALCO_SLUG . '_field_store_id'],
            'items'      => array(),
            'weborderid' => $order_id,
            'payment'         => $payment,
            'shippingAddress' => $shipping_address,
            'billingAddress'  => $billing_address,
            'TransType' => '7',
            'UoMDivideByDecimal' => true
//            'orderMeta' => print_r($order->get_items( 'gift_card' ), true)
        );

        $giftcard_total = 0;
        if (is_plugin_active('woocommerce-gift-cards/woocommerce-gift-cards.php')){
            $giftcards = $order->get_items('gift_card');
            if(is_array($giftcards) && count($giftcards) > 0){
                $aralco_order['GiftCards'] = array();

                /** @var WC_GC_Order_Item_Gift_Card $giftcard */
                foreach ($giftcards as $giftcard) {
                    $amount = $giftcard->get_amount();
                    $giftcard_total += $amount;
                    $aralco_order['GiftCards'][] = array(
                        'Code' => str_replace('-', '', $giftcard->get_code()),
                        'Used' => $amount
                    );
                }

                $order_total = WC_GC()->order->get_order_total($order);
                $aralco_order['payment']['total'] = $order_total;
                $points_value = $order->get_meta('aralco_points_value', 'true', 'edit');
                if(!empty($points_value)) {
                    $payment['totalPaid'] = $order_total - $points_value - $giftcard_total;
                } else {
                    $payment['totalPaid'] = $order_total - $giftcard_total;
                }
            }
        }

        if(isset($options[ARALCO_SLUG . '_field_order_is_quote']) &&
            $options[ARALCO_SLUG . '_field_order_is_quote'] == '1') {
            $aralco_order['TransType'] = '8';
        }

        if(isset($options[ARALCO_SLUG . '_field_reference_number_enabled']) &&
            $options[ARALCO_SLUG . '_field_reference_number_enabled'] == '1') {
            $aralco_order['ReferenceNo'] = $order->get_meta(ARALCO_SLUG . '_reference_number');
        }

        if($is_local_pickup){
            $aralco_order['FromStoreId'] = $pickup_store_id;
            $aralco_order['ShipVia'] = 'Local Pickup';
        }

        $customer_note = $order->get_customer_note();
        if(isset($customer_note) && !empty($customer_note)){
            $aralco_order['Remarks'] = $customer_note;
        }

        $gift_card_code = get_option(ARALCO_SLUG . '_giftcard_product_code', null);

        /**
         * @var $item WC_Order_Item_Product
         */
        foreach ($order->get_items() as $item){

            $gc_amount = $item->get_meta('wc_gc_giftcard_amount');
            $is_gc = !empty($gc_amount);

            $remark = $item->get_meta('notes');

            $product = $item->get_product();
            if($is_gc) {
                $gc_base_id = wc_get_product_id_by_sku($gift_card_code);
                if($gc_base_id && $gc_base_id > 0) {
                    $product = wc_get_product($gc_base_id);
                }
            }
            $quantity = $item->get_quantity();
            $price = floatval($item->get_subtotal()) / floatval($item->get_quantity());

            $sell_by = get_post_meta($product->get_id(), '_aralco_sell_by', true);
            $precision = 2;
            if(is_array($sell_by)) {
                $decimals = (is_numeric($sell_by['decimals']))? $sell_by['decimals'] : 0;
                if ($decimals > 0) {
//                    $quantity = $quantity / (10 ** $decimals);
//                    $price = $price * (10 ** $decimals);
                    $precision += $decimals;
                }
            }

            $price = round($price, $precision);
            $grids = get_post_meta($product->get_id(), '_aralco_grids', true);
            $aralco_product_id = get_post_meta($product->get_id(), '_aralco_id', true);
            if($aralco_product_id == false) {
                $aralco_product_id = get_post_meta($product->get_parent_id(), '_aralco_id', true);
            }


            // TODO: Remove this. Aralco API only accepts int for quantity?
            if ($quantity < 1) $quantity = 1;
            $quantity = round($quantity);

            $item_to_push = array(
                'productId'    => intval($aralco_product_id),
                'code'         => $product->get_sku(),
                'price'        => $price,
                'discount'     => 0,
                'quantity'     => $quantity,
                'weight'       => 0,
                'gridId1'      => (is_array($grids) && isset($grids['gridId1']) && !empty($grids['gridId1'])) ? $grids['gridId1'] : null,
                'gridId2'      => (is_array($grids) && isset($grids['gridId2']) && !empty($grids['gridId2'])) ? $grids['gridId2'] : null,
                'gridId3'      => (is_array($grids) && isset($grids['gridId3']) && !empty($grids['gridId3'])) ? $grids['gridId3'] : null,
                'gridId4'      => (is_array($grids) && isset($grids['gridId4']) && !empty($grids['gridId4'])) ? $grids['gridId4'] : null,
                'dimensionId1' => (is_array($grids) && isset($grids['dimensionId1']) && !empty($grids['dimensionId1'])) ? $grids['dimensionId1'] : null,
                'dimensionId2' => (is_array($grids) && isset($grids['dimensionId2']) && !empty($grids['dimensionId2'])) ? $grids['dimensionId2'] : null,
                'dimensionId3' => (is_array($grids) && isset($grids['dimensionId3']) && !empty($grids['dimensionId3'])) ? $grids['dimensionId3'] : null,
                'dimensionId4' => (is_array($grids) && isset($grids['dimensionId4']) && !empty($grids['dimensionId4'])) ? $grids['dimensionId4'] : null
            );

            if(!empty($remark)){
                $item_to_push['remark'] = $remark;
            }

            if($is_local_pickup){
                $item_to_push['SellFromStoreID'] = $pickup_store_id;
            }
            if($is_gc){
                for($i = 0; $i < 10; $i++){
                    $number = self::get_giftcard_number($item->get_id());
                    if($number == false){
                        sleep(1);
                        continue;
                    } else {
                        $item_to_push['GiftCardNo'] = $number;
                    }
                }
                if(!isset($item_to_push['GiftCardNo'])){
                    return new WP_Error('gc_not_found', __('Could not locate the GiftCard number after 10 attempts. Please report this.', ARALCO_SLUG));
                }
            }

            array_push($aralco_order['items'], $item_to_push);
        }

        $aralco_order = apply_filters('aralco_order_json', $aralco_order);

        if (isset($just_return) && $just_return) return $aralco_order;

        update_option('aralco_last_submission', $aralco_order);

        update_post_meta($order_id, '_aralco_order_array', $aralco_order);

        $result = Aralco_Connection_Helper::createOrder($aralco_order);
        if (!$result instanceof WP_Error) {
            update_post_meta($order_id, 'aralco_order_success', 'yes');
            return true;
        }

        update_post_meta($order_id, 'aralco_order_success', 'no');

        return $result;
    }

    private static function get_giftcard_number($order_item_id){
        $results = WC_GC()->db->giftcards->query(array(
            'return'          => 'objects',
            'order_item_id'   => $order_item_id,
            'limit'           => 1,
        ));
        if(count($results) > 0) {
            /** @var WC_GC_Gift_Card_Data $gcd */
            $gcd = array_pop($results);
            return str_replace('-', '', $gcd->get_code());
        }
        return false;
    }
}
