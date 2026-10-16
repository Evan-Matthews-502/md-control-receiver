<?php

/**
 * Authenticate MD Control API requests using the 'md-control-key' header.
 */
function md_control_authenticate(WP_REST_Request $request) {
    $provided_key = $request->get_header('md-control-key');
    $expected_key = get_option('md_control_api_key');


    if (!is_string($expected_key) || $expected_key === '') {
        return new WP_Error('missing_api_key', 'API key is not set.', ['status' => 500]);
    }

    return is_string($provided_key) && hash_equals($expected_key, $provided_key);
}
