<?php
/*
Plugin Name: Minimalist Forms
Plugin URI:  https://github.com/andrewklimek/
Description: a weird shortcode-based forms plugin
Version:     0.0.1
Author:      Andrew J Klimek
Author URI:  https://andrewklimek.com
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Minimalist Forms is free software: you can redistribute it and/or modify 
it under the terms of the GNU General Public License as published by the Free 
Software Foundation, either version 2 of the License, or any later version.

Minimalist Forms is distributed in the hope that it will be useful, but without 
any warranty; without even the implied warranty of merchantability or fitness for a 
particular purpose. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with 
Minimalist Forms. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
*/



/*****
* Process the [mnmlform_field] shortcode
* Accepts the name of one excercise, various parameters, and
* Generates HTML for a section of a rep form
*****/
function mnmlform_field( $a, $c='', $t )
{
	if ( empty( $a['name'] ) ) return "<p>Please supply a 'name' parameter in the [{$t}] shortcode.</p>";

	// to ensure consistency in the database and safety in the HTML attributes,
	// format exercise name to onlie have lowercase letters and nothing else (no spaces either)
	$a['name'] = preg_replace( '/[^a-z0-9]/', '', strtolower( $a['name'] ) );
	
	if ( empty( $a['type'] ) ) $a = ['type' => "text"] + $a;
	
	// required fields
	$required = 'required';// default
	if ( isset( $a['required'] ) )
	{
		$required = ( '0' == $a['required'] || 'false' == $a['required'] ) ? '' : 'required';
		unset( $a['required'] );
	}
	
	// wrapper
	$out = "";// don't need a wrapper div do we?
	
	// labels
	if ( !empty( $a['label'] ) )
	{
		if ( empty( $a['id'] ) ) $a['id'] = $a['name'];
		$out .= "<label for='{$a['id']}'>{$a['label']}</label>";
		unset( $a['label'] );
	}
	
	// field

	$out .= "<input";
	
	foreach ( $a as $key => $value )
	{
		$out .= " {$key}='{$value}'";
	}
	
	$out .= $required;

	$out .= ">";

	return $out;
}
add_shortcode('mnmlform_field', 'mnmlform_field');


/*****
* Process the [mnmlform] shortcode
* Generates the HTML to begin and end a rep form
* Requires 3 parameters: program, week, and day
* Requires nested [mnmlform_field] shortcodes to work ([mnmlform][mnmlform_field][mnmlform_field][/mnmlform])
*****/
function mnmlform( $a, $c='', $t )
{
	if ( empty( $a['id'] ) ) return "<p>Please supply an 'id' parameter in the [{$t}] shortcode.</p>";
	$submit = !empty( $a['submit'] ) ? $a['submit'] : "Submit";
	$success = !empty( $a['success'] ) ? $a['success'] : "Submitted";
	$formid = $a['id'];
	
	$out = "<form id='mnmlform-{$formid}' action='' method='post'>";
	$out .=	"<input type='hidden' name='form_meta[user_id]' value='" . get_current_user_id() . "'>";
	$out .=	"<input type='hidden' name='form_meta[form_id]' value='{$formid}'>";
	// process nested exercise shortcodes [mnmlform_field]
	$out .= do_shortcode( $c );
	$out .=	"<button type='submit' name='submit'>{$submit}</button>";
	$out .=	"</form>";
	
	$out .=	"<script>(function(){
		function submit(e) {
			e.preventDefault();
			var form = this;
			btn = this.querySelector('[type=submit]');
			if ( ! form.checkValidity() ) {// stupid custom validation for safari
				form.insertAdjacentHTML('beforeend','<style>#mnmlcontact :invalid{border-color:#f66;}</style>');
			} else {
			btn.disabled = true;
			btn.style.opacity = '.3';
			var xhr = new XMLHttpRequest();
			xhr.open('POST', '/wp-json/mnmlforms/v1/post');
			xhr.onload = function() {
				var resp = JSON.parse(this.response);
				if ( resp ){
					btn.textContent = resp;
				} else {
					btn.innerHTML = '{$success}';
				}
				setTimeout('btn.innerHTML = \"{$submit}\";', 3000);
				btn.disabled = false;
				btn.style.opacity = '';
			};
			xhr.onerror = function() {
				btn.disabled = false;
			};
			xhr.send(new FormData(this));
		}}
		document.getElementById('mnmlform-{$formid}').addEventListener('submit', submit);
	})();</script>";

	return $out;
}
add_shortcode('mnmlform', 'mnmlform');




/*****
* Register a custom WP API endpoint to submit the forms to
*****/
add_action( 'rest_api_init', function ()
{
	register_rest_route( 'mnmlforms/v1', '/post', array(
		'methods' => 'POST',
		'callback' => 'ajk_mnmlforms_process',
		'permission_callback' => '__return_true',
	) );
} );

/*****
* Process form submissions.
* This is the callback function for the custom WP API endpoint defined above.
*****/
function ajk_mnmlforms_process( $request )
{
	// store the post data
	$data = $request->get_params();
		
	// $data['form_meta'] holds the hidden fields: user_id, program, week, day
	// Store these seperately and remove them from $data so we can process the real input fields seperately
	$form_meta = $data['form_meta'];
	unset( $data['form_meta'] );
	
	if ( ! array_filter( $data ) )
	{
		return 'Please fill in at least one field';
	}
	
	global $wpdb;
	
	$email_body = '';
	$send_email = true;
	
	$failure = 0;
	
	$success = $wpdb->insert( $wpdb->prefix . 'mnmlform_entries', [
		'user' => $form_meta['user_id'],
		'form' => $form_meta['form_id'],
		'gmt_date' => gmdate('Y-m-d H:i:s'),
	]);
	
	// new id created in auto-incremented column
	$entry_id = $wpdb->insert_id;
	
	if ( $success === false ) ++$failure;
	
	foreach ( $data as $key => $value)
	{
		$success = null;
	
		if ( $value )
		{
			$success = $wpdb->insert( $wpdb->prefix . 'mnmlform', [
				'name' => $key,
				'value' => $value,
				'entry' => $entry_id,
				'form' => $form_meta['form_id'],
			]);
			
			if ( $send_email )
			{
				$email_body .= "{$key}: {$value}\n";
			}
		}
		if ( $success === false ) ++$failure;	
	}
	
	// email
	$email_to = get_option('admin_email');
	
	$email_subject = get_option('blogname') ." - Entry on Form “{$form_meta['form_id']}”";
	
	$email_sent = wp_mail( $email_to, $email_subject, $email_body );

	if ( ! $email_sent )
	{
			error_log("mail send failed on form {$form_meta['form_id']} entry $entry_id");
	}

	// return
	if ( $failure )
	{
		return "There was an error, please try again";
		error_log("$failure DB inserts/updates failed");
	}
	else return;
		
}

/*****
* Create the custom database table
*****/
function ajk_mnmlforms_create_database()
{
	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );// to use dbDelta()
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE {$wpdb->prefix}mnmlform (
		id bigint(20) unsigned NOT NULL auto_increment,
		name text,
		value text,
		entry bigint(20) unsigned,
		form text,
		PRIMARY KEY  (id)
	) ENGINE=InnoDB $charset_collate;" );
	
	dbDelta( "CREATE TABLE {$wpdb->prefix}mnmlform_entries (
		entry bigint(20) unsigned NOT NULL auto_increment,
		user text,
		form text,
		gmt_date datetime NOT NULL default '0000-00-00 00:00:00',
		PRIMARY KEY  (entry)
	) ENGINE=InnoDB $charset_collate;" );

}

// stuff to do on activation only... create database
function ajk_mnmlforms_activation()
{
	ajk_mnmlforms_create_database();
}
register_activation_hook( __FILE__, 'ajk_mnmlforms_activation' );

