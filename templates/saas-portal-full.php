<?php
defined('ABSPATH') || exit; ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html(get_the_title()); ?></title>
<style>html,body{margin:0;padding:0;height:100%;}</style></head>
<body class="saas-portal-fullscreen"><?php while (have_posts()) : the_post(); echo do_shortcode('[saas_panel]'); endwhile; ?></body>
</html>
