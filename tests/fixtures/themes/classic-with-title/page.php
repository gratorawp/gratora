<?php
get_header();
while (have_posts()) {
    the_post();
    the_title('<h1 class="entry-title">', '</h1>');
    the_content();
}
get_footer();
