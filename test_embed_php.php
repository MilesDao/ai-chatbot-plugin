<?php
require_once('../../../wp-load.php');
$api_key = get_option('ai_chatbot_openrouter_api_key');
$embed_model = get_option('ai_chatbot_openrouter_embed_model');
echo "EMBED MODEL IN DB: " . $embed_model . "\n";

require_once('includes/class-openrouter-client.php');
$client = new OpenRouter_API_Client($api_key, 'foo', $embed_model);
$vector = $client->get_embedding('test');
if (is_wp_error($vector)) {
    echo "ERROR: " . $vector->get_error_message() . "\n";
} else {
    echo "DIMENSION IN PHP: " . count($vector) . "\n";
}
