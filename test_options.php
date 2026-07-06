<?php
require_once('../../../wp-load.php');
$chat_model = get_option( 'ai_chatbot_openrouter_model' );
$embed_model = get_option( 'ai_chatbot_openrouter_embed_model' );
echo "CHAT: " . $chat_model . "\n";
echo "EMBED: " . $embed_model . "\n";
