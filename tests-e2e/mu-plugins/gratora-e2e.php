<?php

// The Add-ons screen asks gratora.net for its lists. A test site shows the built-in one.
add_filter('gratora.addons.remote', '__return_false');
