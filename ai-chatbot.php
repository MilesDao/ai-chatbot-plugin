<?php
/**
 * Plugin Name: AI Chatbot
 * Plugin URI: https://github.com/google-deepmind/ai-chatbot
 * Description: A self-contained AI Chatbot with Retrieval-Augmented Generation (RAG) powered by the Gemini API. Index local files in WordPress MySQL and query them in real-time using a beautiful glassmorphic floating widget.
 * Version: 1.0.5
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
define( 'AI_CHATBOT_VERSION', '1.0.5' );

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
        embedding longtext NOT NULL,
        token_count int(11) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY document_id (document_id)
    ) $charset_collate;";
    dbDelta( $sql_chunks );

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
                    "SELECT role, content FROM $table_messages WHERE conversation_id = %d ORDER BY created_at DESC LIMIT 10",
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
        $chat_model = get_option( 'ai_chatbot_openrouter_model', 'openai/gpt-oss-120b:free' );
        $embed_model = get_option( 'ai_chatbot_openrouter_embed_model', 'nvidia/llama-nemotron-embed-vl-1b-v2:free' );
        $client = new OpenRouter_API_Client( $api_key, $chat_model, $embed_model );

        $search_query = $message;
        if ( ! empty( $history ) ) {
            $search_query = $client->reformulate_query( $message, $history );
        }

        $rag_manager = new AI_Chatbot_Manager();
        
        // 2. Fetch relevant chunks from the database/Pinecone
        $k = intval( get_option( 'ai_chatbot_top_k', '3' ) );
        $relevant_chunks = $rag_manager->search_similar_chunks( $search_query, $k );

        // Use the user's requested persona prompt as default
        $default_prompt = "HÃY ĐÓNG VAI LÀ \"ANH/CHỊ\" TRONG BAN TƯ VẤN TUYỂN SINH - MỘT NGƯỜI ANH/NGƯỜI CHỊ KHÓA TRÊN SÀNH ĐIỆU, THÂN THIỆN, THẤU HIỂU VÀ CỰC KỲ TÂM LÝ.

Nhiệm vụ của bạn là lắng nghe, giải đáp thắc mắc và định hướng ngành học cho các em học sinh (gọi là \"Em\") dựa TRÊN DUY NHẤT TÀI LIỆU ĐƯỢC CUNG CẤP dưới đây.

[DỮ LIỆU TRƯỜNG HỌC]
{context}
[/DỮ LIỆU TRƯỜNG HỌC]

CHÂN DUNG & PHONG CÁCH CHAT (PERSONA):
- Ngôn ngữ: Tự nhiên như người thật đang gõ chat, sử dụng các từ ngữ gần gũi với Gen Z nhưng vẫn giữ sự lịch sự (Ví dụ: \"Hế nhô em\", \"Chill thôi đừng lo nè\", \"Bật mí nha\", \"Chuẩn luôn\", \"Ui ngành này hot lắm á\").
- Biểu cảm: Luôn đồng cảm với áp lực chọn ngành của học sinh. Biết khen ngợi khi học sinh chia sẻ sở thích cá nhân.
- Tốc độ thông tin: Không trả lời nguyên một bài văn dài. Hãy ngắt dòng, chia nhỏ ý như đang nhắn tin Messenger/Zalo.

QUY TẮC SỬ DỤNG EMOJI (ICON):
- Mỗi tin nhắn chỉ dùng từ 2 - 4 emoji. Không lạm dụng icon ở mọi đầu dòng khiến rối mắt.
- Sử dụng các icon mang tính biểu cảm, định hướng visual tốt: ✨ (nhấn mạnh điểm đặc biệt), 💡 (khi đưa ra giải pháp/gợi ý), 🎯 (mục tiêu/ngành phù hợp), 🤔 (khi cùng suy nghĩ), 🙌 hoặc 💖 (động viên/chào hỏi).

NGUYÊN TẮC XỬ LÝ THÔNG TIN (RAG):
1. Chỉ tư vấn thông tin có trong thẻ [DỮ LIỆU TRƯỜNG HỌC]. Tuyệt đối không tự bịa thông tin bên ngoài.
2. Nếu dữ liệu không có, hãy trả lời khéo léo: \"Ui, cái này trong tài liệu hiện tại của anh/chị chưa cập nhật rồi 😢. Để anh/chị hỏi lại phòng tuyển sinh rồi nhắn em sau nha, hoặc em nhắn lại số điện thoại để các thầy cô gọi hỗ trợ trực tiếp nhen! 💖\"
3. Luôn kết thúc bằng một câu hỏi gợi mở để giữ mạch trò chuyện (Ví dụ: \"Em có muốn xem thử lịch học ngành này có nặng không nè?\").";

        // 2. Format custom instructions/context
        $context_text = "";
        if ( ! empty( $relevant_chunks ) ) {
            $context_text .= $default_prompt;
            $context_text .= "\n\n=== ĐOẠN TRÍCH TÀI LIỆU LIÊN QUAN ===\n";
            foreach ( $relevant_chunks as $idx => $chunk ) {
                $doc_name = $chunk['document_name'];
                $context_text .= "Đoạn trích " . ($idx + 1) . " (Tệp nguồn: $doc_name):\n" . $chunk['content'] . "\n\n";
            }
            $context_text .= "=== KẾT THÚC BỐI CẢNH ===\n\n";
        } else {
            // General conversation instructions if database has no records
            $context_text .= "Bạn là một trợ lý chatbot AI chuyên nghiệp. Tài liệu hiện tại đang trống. Hãy trả lời câu hỏi lịch sự bằng tiếng Việt và khuyên họ tải tài liệu lên trong trang quản trị để cá nhân hóa câu trả lời.";
        }

        // Custom system prompt/persona from settings
        $system_prompt = get_option( 'ai_chatbot_system_prompt', "Bạn là tư vấn viên vô cùng thân thiện, nhiệt tình và am hiểu của nhà trường. Nhiệm vụ của bạn là giải đáp mọi thắc mắc của sinh viên (tuyển sinh, ngành học, học phí, thủ tục, đời sống...).\n\nNGUYÊN TẮC TRẢ LỜI:\n1. Xưng hô: Xưng là 'mình' hoặc 'trường mình' và gọi người dùng là 'bạn' hoặc 'em' một cách gần gũi.\n2. Thái độ: Trả lời tự nhiên, thân thiện như một người anh/chị khóa trên. Dùng từ ngữ đệm (nhé, nha, ạ...) và thỉnh thoảng dùng emoji 😊✨.\n3. Nội dung: CHỈ dựa trên tài liệu được cung cấp. Nếu không có thông tin, hãy xin lỗi khéo léo và khuyên liên hệ Phòng Đào tạo/Hotline. Không tự bịa thông tin.\n4. Trả lời súc tích, đi thẳng vào vấn đề. Nếu câu hỏi chưa rõ, hãy nhẹ nhàng hỏi lại." );
        $split_instruction = "QUAN TRỌNG: Để trả lời tự nhiên giống con người đang nhắn tin, hãy ngắt câu trả lời thành nhiều tin nhắn ngắn. Sử dụng CỤM TỪ <Tách box chat> giữa các phần để ngắt tin nhắn. Ví dụ:\nXin chào bạn\n<Tách box chat>\nỞ trường mình có các ngành như...\n\nCHÚ Ý: Chỉ dùng <Tách box chat> (không tự ý thay đổi).";
        $context_text = $system_prompt . "\n\n" . $split_instruction . "\n\n" . $context_text;

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
                    array( 'lead_name' => $lead_name, 'updated_at' => current_time( 'mysql' ) ),
                    array( 'id' => $conversation_id ),
                    array( '%s', '%s' ),
                    array( '%d' )
                );
            } else {
                $wpdb->update(
                    $table_conversations,
                    array( 'updated_at' => current_time( 'mysql' ) ),
                    array( 'id' => $conversation_id ),
                    array( '%s' ),
                    array( '%d' )
                );
            }
        } else {
            $inserted = $wpdb->insert(
                $table_conversations,
                array(
                    'session_id' => $session_id,
                    'lead_name'  => $lead_name,
                    'created_at' => current_time( 'mysql' ),
                    'updated_at' => current_time( 'mysql' ),
                ),
                array( '%s', '%s', '%s', '%s' )
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
                'created_at'      => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s' )
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
            "SELECT role, content, created_at FROM $table_messages WHERE conversation_id = %d ORDER BY created_at ASC",
            $conversation->id
        ), ARRAY_A );

        wp_send_json_success( array(
            'messages'  => $messages,
            'lead_name' => $conversation->lead_name,
        ) );
    }
}

// Instantiate the primary plugin class
add_action( 'plugins_loaded', array( 'AI_Chatbot_Chatbot', 'get_instance' ) );
