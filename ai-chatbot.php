<?php
/**
 * Plugin Name: AI Chatbot
 * Plugin URI: https://github.com/google-deepmind/ai-chatbot
 * Description: A self-contained AI Chatbot with Retrieval-Augmented Generation (RAG) powered by the Gemini API. Index local files in WordPress MySQL and query them in real-time using a beautiful glassmorphic floating widget.
 * Version: 1.0.8
 * Author: Dao Trung
 * Text Domain: ai-chatbot
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Define Constants
define( 'AI_CHATBOT_PATH', plugin_dir_path( __FILE__ ) );
define( 'AI_CHATBOT_URL', plugin_dir_url( __FILE__ ) );
define( 'AI_CHATBOT_VERSION', '1.0.8' );

/**
 * Custom activation routine to create database tables for documents and chunks.
 */
function ai_chatbot_activate() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    // 1. Table for tracking uploaded documents
    $table_docs = $wpdb->prefix . 'ai_chatbot_documents';
    $sql_docs = "CREATE TABLE $table_docs (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        file_type varchar(50) NOT NULL,
        file_size bigint(20) NOT NULL,
        chunk_count int(11) DEFAULT 0,
        status varchar(50) DEFAULT 'indexed',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql_docs );

    // 2. Table for storing chunked text and 768-dim embeddings JSON
    $table_chunks = $wpdb->prefix . 'ai_chatbot_chunks';
    $sql_chunks = "CREATE TABLE $table_chunks (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        document_id bigint(20) NOT NULL,
        content longtext NOT NULL,
        parent_content longtext,
        embedding longtext NOT NULL,
        token_count int(11) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY document_id (document_id),
        FULLTEXT KEY idx_content (content)
    ) $charset_collate;";
    dbDelta( $sql_chunks );

    // Ensure FULLTEXT index exists for older installations
    $index_check = $wpdb->get_results("SHOW INDEX FROM $table_chunks WHERE Key_name = 'idx_content'");
    if (empty($index_check)) {
        $wpdb->query("ALTER TABLE $table_chunks ADD FULLTEXT idx_content (content)");
    }

    // 3. Table for storing collected customer leads (Name, Email, Phone)
    $table_leads = $wpdb->prefix . 'ai_chatbot_leads';
    $sql_leads = "CREATE TABLE $table_leads (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        email varchar(255) NOT NULL,
        phone varchar(50) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql_leads );

    // 4. Table for storing conversation sessions
    $table_conversations = $wpdb->prefix . 'ai_chatbot_conversations';
    $sql_conversations = "CREATE TABLE $table_conversations (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        session_id varchar(64) NOT NULL,
        lead_id bigint(20) DEFAULT NULL,
        lead_name varchar(255) DEFAULT '',
        status varchar(20) DEFAULT 'bot',
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY session_id (session_id),
        KEY lead_id (lead_id)
    ) $charset_collate;";
    dbDelta( $sql_conversations );

    // 5. Table for storing individual chat messages
    $table_messages = $wpdb->prefix . 'ai_chatbot_messages';
    $sql_messages = "CREATE TABLE $table_messages (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        conversation_id bigint(20) NOT NULL,
        role varchar(16) NOT NULL,
        content longtext NOT NULL,
        chat_type varchar(10) DEFAULT 'ai',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY conversation_id (conversation_id)
    ) $charset_collate;";
    dbDelta( $sql_messages );
}
register_activation_hook( __FILE__, 'ai_chatbot_activate' );

/**
 * Load plugin dependencies.
 */
require_once AI_CHATBOT_PATH . 'includes/class-openrouter-client.php';
require_once AI_CHATBOT_PATH . 'includes/class-rag-manager.php';
require_once AI_CHATBOT_PATH . 'includes/class-admin-settings.php';

/**
 * Primary Plugin Bootstrap Class
 */
class AI_Chatbot_Chatbot {
    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Self-healing database check: Auto-create tables if database version is outdated
        if ( is_admin() ) {
            $db_version = get_option( 'ai_chatbot_db_version', '0' );
            if ( version_compare( $db_version, AI_CHATBOT_VERSION, '<' ) ) {
                ai_chatbot_activate();
                update_option( 'ai_chatbot_db_version', AI_CHATBOT_VERSION );
            }
        }

        // Enqueue Assets for Frontend
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

        // Render Chat Widget in Footer (For Floating Bubble - Option A)
        add_action( 'wp_footer', array( $this, 'render_chat_widget' ) );

        // Shortcode support
        add_shortcode( 'ai_chatbot', array( $this, 'render_chat_widget_shortcode' ) );

        // AJAX Handlers
        add_action( 'wp_ajax_ai_chatbot_chat_query', array( $this, 'handle_chat_query' ) );
        add_action( 'wp_ajax_nopriv_ai_chatbot_chat_query', array( $this, 'handle_chat_query' ) );
        
        add_action( 'wp_ajax_ai_chatbot_submit_lead', array( $this, 'handle_submit_lead' ) );
        add_action( 'wp_ajax_nopriv_ai_chatbot_submit_lead', array( $this, 'handle_submit_lead' ) );

        // Conversation persistence AJAX handlers
        add_action( 'wp_ajax_ai_chatbot_save_message', array( $this, 'handle_save_message' ) );
        add_action( 'wp_ajax_nopriv_ai_chatbot_save_message', array( $this, 'handle_save_message' ) );
        add_action( 'wp_ajax_ai_chatbot_get_history', array( $this, 'handle_get_history' ) );
        add_action( 'wp_ajax_nopriv_ai_chatbot_get_history', array( $this, 'handle_get_history' ) );

        add_action( 'wp_ajax_ai_chatbot_switch_mode', array( $this, 'handle_switch_mode' ) );
        add_action( 'wp_ajax_nopriv_ai_chatbot_switch_mode', array( $this, 'handle_switch_mode' ) );
    }

    /**
     * Enqueue CSS and JS for frontend chat widget.
     */
    public function enqueue_frontend_assets() {
        // Enqueue Google Font 'Outfit' for a beautiful premium typography
        wp_enqueue_style( 'ai-chatbot-font', 'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap', array(), null );

        wp_enqueue_style( 'ai-chatbot-style', AI_CHATBOT_URL . 'assets/css/chat-style.css', array(), time() );

        wp_enqueue_script( 'ai-chatbot-script', AI_CHATBOT_URL . 'assets/js/chat-script.js', array( 'jquery' ), time(), true );

        // Localize variables to JS (e.g. AJAX url, nonce, UI configuration)
        $primary_color = get_option( 'ai_chatbot_primary_color', '#0ea5e9' ); // Default electric blue
        $welcome_msg = get_option( 'ai_chatbot_welcome_message', 'Trường Cao đẳng Kinh tế Công nghệ Hà Nội xin chào! Chúng tôi có thể giúp gì cho bạn hôm nay?' );
        $require_lead = get_option( 'ai_chatbot_require_lead_form', '1' );

        wp_localize_script( 'ai-chatbot-script', 'aiChatbotVars', array(
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'ai_chatbot_nonce' ),
            'primary_color' => $primary_color,
            'welcome_msg'   => $welcome_msg,
            'require_lead'  => $require_lead
        ) );
    }

    /**
     * Renders floating chat widget in footer.
     */
    public function render_chat_widget() {
        // Check if chatbot is enabled globally in settings
        $enabled = get_option( 'ai_chatbot_enable_widget', '1' );
        if ( '1' !== $enabled ) {
            return;
        }

        include AI_CHATBOT_PATH . 'templates/chat-widget.php';
    }

    /**
     * Shortcode [ai_chatbot] handler for inline embedding.
     */
    public function render_chat_widget_shortcode( $atts ) {
        ob_start();
        // Inline version flag so script/styles know how to behave
        $is_inline = true;
        include AI_CHATBOT_PATH . 'templates/chat-widget.php';
        return ob_get_clean();
    }

    /**
     * AJAX handler to process user queries using RAG context and Gemini API.
     */
    public function handle_chat_query() {
        // Verify nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ai_chatbot_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed. Please reload the page.' ) );
        }

        $message = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : '';
        if ( empty( $message ) ) {
            wp_send_json_error( array( 'message' => 'Empty message.' ) );
        }

        $api_key = get_option( 'ai_chatbot_openrouter_api_key', '' );
        if ( empty( $api_key ) ) {
            wp_send_json_error( array( 'message' => 'Chatbot chưa được cấu hình. Vui lòng thêm OpenRouter API Key trong phần cài đặt.' ) );
        }

        // 0. Fetch history
        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        $history = array();
        if ( ! empty( $session_id ) ) {
            global $wpdb;
            $table_conversations = $wpdb->prefix . 'ai_chatbot_conversations';
            $table_messages      = $wpdb->prefix . 'ai_chatbot_messages';
            
            $conversation = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM $table_conversations WHERE session_id = %s",
                $session_id
            ) );
            
            if ( $conversation ) {
                $messages_db = $wpdb->get_results( $wpdb->prepare(
                    "SELECT role, content FROM $table_messages WHERE conversation_id = %d AND chat_type = 'ai' ORDER BY created_at DESC LIMIT 10",
                    $conversation->id
                ) );
                
                if ( $messages_db ) {
                    $messages_db = array_reverse( $messages_db );
                    foreach ( $messages_db as $msg ) {
                        $role = ( $msg->role === 'bot' ) ? 'assistant' : 'user';
                        $history[] = array(
                            'role' => $role,
                            'content' => $msg->content
                        );
                    }
                }
            }
        }

        // 1. Reformulate Query (Contextual Retrieval)
        $chat_model = get_option( 'ai_chatbot_openrouter_model' );
        if ( empty($chat_model) ) $chat_model = 'deepseek/deepseek-v4-flash';
        $embed_model = get_option( 'ai_chatbot_openrouter_embed_model' );
        if ( empty($embed_model) ) $embed_model = 'qwen/qwen3-embedding-8b';
        $client = new OpenRouter_API_Client( $api_key, $chat_model, $embed_model );

        $search_query = $message;
        $enable_reformulate = get_option( 'ai_chatbot_enable_reformulate', '1' );
        
        if ( '1' === $enable_reformulate && ! empty( $history ) ) {
            $search_query = $client->reformulate_query( $message, $history );
        }

        $rag_manager = new AI_Chatbot_Manager();
        
        // 2. Fetch relevant chunks from the database/Pinecone
        $k = intval( get_option( 'ai_chatbot_top_k', '3' ) );
        $relevant_chunks = $rag_manager->search_similar_chunks( $search_query, $k );

        // 2. Format context from database/Pinecone
        $chunks_text = "";
        if ( ! empty( $relevant_chunks ) ) {
            foreach ( $relevant_chunks as $idx => $chunk ) {
                $doc_name = $chunk['document_name'];
                $chunks_text .= "Đoạn trích " . ($idx + 1) . " (Tệp nguồn: $doc_name):\n" . $chunk['content'] . "\n\n";
            }
        } else {
            $chunks_text = "Không có tài liệu nào liên quan.";
        }

        // Custom system prompt/persona from settings
        $system_prompt = get_option( 'ai_chatbot_system_prompt', "HÃY ĐÓNG VAI LÀ \"ANH/CHỊ\" TRONG BAN TƯ VẤN TUYỂN SINH - MỘT NGƯỜI ANH/NGƯỜI CHỊ KHÓA TRÊN SÀNH ĐIỆU, THÂN THIỆN, THẤU HIỂU VÀ CỰC KỲ TÂM LÝ.\n\nNhiệm vụ của bạn là lắng nghe, giải đáp thắc mắc và định hướng ngành học cho các em học sinh (gọi là \"Em\") dựa TRÊN DUY NHẤT TÀI LIỆU ĐƯỢC CUNG CẤP dưới đây.\n\n[DỮ LIỆU TRƯỜNG HỌC]\n{context}\n[/DỮ LIỆU TRƯỜNG HỌC]\n\nCHÂN DUNG & PHONG CÁCH CHAT (PERSONA):\n- Ngôn ngữ: Tự nhiên như người thật đang gõ chat, sử dụng các từ ngữ gần gũi với Gen Z nhưng vẫn giữ sự lịch sự.\n- Biểu cảm: Luôn đồng cảm với áp lực chọn ngành của học sinh.\n- Tốc độ thông tin: Không trả lời nguyên một bài văn dài. Hãy ngắt dòng.\n\nNGUYÊN TẮC XỬ LÝ THÔNG TIN (RAG):\n1. Chỉ tư vấn thông tin có trong thẻ [DỮ LIỆU TRƯỜNG HỌC]. Tuyệt đối không tự bịa thông tin bên ngoài.\n2. Nếu dữ liệu không có, hãy trả lời khéo léo: \"Ui, câu hỏi này anh/chị chưa có thông tin chính thức rồi 😢. Để anh/chị hỏi lại phòng đào tạo rồi báo em sau nha!\"\n3. Luôn kết thúc bằng một câu hỏi gợi mở để giữ mạch trò chuyện." );
        
        // Inject context into the prompt
        if ( strpos( $system_prompt, '{context}' ) !== false ) {
            $system_prompt = str_replace( '{context}', $chunks_text, $system_prompt );
        } else {
            $system_prompt .= "\n\n[DỮ LIỆU TRƯỜNG HỌC]\n" . $chunks_text . "\n[/DỮ LIỆU TRƯỜNG HỌC]";
        }

        $context_text = $system_prompt;

        // History is now handled above

        // 4. Make OpenRouter API chat call with Streaming
        $client->generate_chat_answer_stream( $message, $context_text, $history );
    }

    /**
     * AJAX handler to submit customer lead information.
     */
    public function handle_submit_lead() {
        check_ajax_referer( 'ai_chatbot_nonce', 'nonce' );

        $name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

        if ( empty( $name ) || empty( $email ) || empty( $phone ) ) {
            wp_send_json_error( array( 'message' => 'Vui lòng điền đầy đủ thông tin để bắt đầu tư vấn.' ) );
        }

        if ( ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => 'Địa chỉ email không hợp lệ.' ) );
        }

        global $wpdb;
        $table_leads = $wpdb->prefix . 'ai_chatbot_leads';

        $inserted = $wpdb->insert(
            $table_leads,
            array(
                'name'  => $name,
                'email' => $email,
                'phone' => $phone
            ),
            array( '%s', '%s', '%s' )
        );

        if ( ! $inserted ) {
            wp_send_json_error( array( 'message' => 'Không thể lưu thông tin. Vui lòng thử lại.' ) );
        }

        wp_send_json_success( array( 'message' => 'Thông tin đăng ký thành công!' ) );
    }

    /**
     * AJAX handler to save/update a conversation session and its messages.
     */
    public function handle_save_message() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ai_chatbot_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        $role       = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : '';
        $content    = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';
        $lead_name  = isset( $_POST['lead_name'] ) ? sanitize_text_field( wp_unslash( $_POST['lead_name'] ) ) : '';
        $chat_type  = isset( $_POST['chat_type'] ) ? sanitize_text_field( wp_unslash( $_POST['chat_type'] ) ) : 'ai';

        if ( empty( $session_id ) || empty( $role ) || empty( $content ) ) {
            wp_send_json_error( array( 'message' => 'Missing required fields.' ) );
        }

        if ( ! in_array( $role, array( 'user', 'bot' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid role.' ) );
        }

        global $wpdb;
        $table_conversations = $wpdb->prefix . 'ai_chatbot_conversations';
        $table_messages      = $wpdb->prefix . 'ai_chatbot_messages';

        // Get or create conversation
        $conversation = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table_conversations WHERE session_id = %s",
            $session_id
        ) );

        if ( $conversation ) {
            $conversation_id = $conversation->id;
            // Update lead_name if provided
            if ( ! empty( $lead_name ) && empty( $conversation->lead_name ) ) {
                $wpdb->update(
                    $table_conversations,
                    array( 'lead_name' => $lead_name, 'updated_at' => current_time( 'mysql' ), 'status' => $chat_type === 'human' ? 'human' : 'bot' ),
                    array( 'id' => $conversation_id ),
                    array( '%s', '%s', '%s' ),
                    array( '%d' )
                );
            } else {
                $wpdb->update(
                    $table_conversations,
                    array( 'updated_at' => current_time( 'mysql' ), 'status' => $chat_type === 'human' ? 'human' : 'bot' ),
                    array( 'id' => $conversation_id ),
                    array( '%s', '%s' ),
                    array( '%d' )
                );
            }
        } else {
            $inserted = $wpdb->insert(
                $table_conversations,
                array(
                    'session_id' => $session_id,
                    'lead_name'  => $lead_name,
                    'status'     => $chat_type === 'human' ? 'human' : 'bot',
                    'created_at' => current_time( 'mysql' ),
                    'updated_at' => current_time( 'mysql' ),
                ),
                array( '%s', '%s', '%s', '%s', '%s' )
            );
            if ( ! $inserted ) {
                wp_send_json_error( array( 'message' => 'Could not create conversation.' ) );
            }
            $conversation_id = $wpdb->insert_id;
        }

        // Save the message
        $inserted = $wpdb->insert(
            $table_messages,
            array(
                'conversation_id' => $conversation_id,
                'role'            => $role,
                'content'         => $content,
                'chat_type'       => $chat_type,
                'created_at'      => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%s' )
        );

        if ( ! $inserted ) {
            wp_send_json_error( array( 'message' => 'Could not save message.' ) );
        }

        wp_send_json_success( array(
            'message_id'      => $wpdb->insert_id,
            'conversation_id' => $conversation_id,
        ) );
    }

    /**
     * AJAX handler to retrieve conversation history for a session.
     */
    public function handle_get_history() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ai_chatbot_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        if ( empty( $session_id ) ) {
            wp_send_json_error( array( 'message' => 'Missing session ID.' ) );
        }

        global $wpdb;
        $table_conversations = $wpdb->prefix . 'ai_chatbot_conversations';
        $table_messages      = $wpdb->prefix . 'ai_chatbot_messages';

        $conversation = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table_conversations WHERE session_id = %s",
            $session_id
        ) );

        if ( ! $conversation ) {
            wp_send_json_success( array( 'messages' => array(), 'lead_name' => '' ) );
        }

        $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT role, content, chat_type, created_at FROM $table_messages WHERE conversation_id = %d ORDER BY created_at ASC",
            $conversation->id
        ), ARRAY_A );

        wp_send_json_success( array(
            'messages'  => $messages,
            'lead_name' => $conversation->lead_name,
        ) );
    }

    /**
     * AJAX handler to explicitly switch conversation mode (bot <-> human)
     */
    public function handle_switch_mode() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ai_chatbot_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        $mode       = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '';

        if ( empty( $session_id ) || ! in_array( $mode, array( 'bot', 'human' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid parameters.' ) );
        }

        global $wpdb;
        $table_conversations = $wpdb->prefix . 'ai_chatbot_conversations';
        
        $wpdb->update(
            $table_conversations,
            array( 'status' => $mode, 'updated_at' => current_time( 'mysql' ) ),
            array( 'session_id' => $session_id ),
            array( '%s', '%s' ),
            array( '%s' )
        );

        wp_send_json_success();
    }
}

// Instantiate the primary plugin class
add_action( 'plugins_loaded', array( 'AI_Chatbot_Chatbot', 'get_instance' ) );
