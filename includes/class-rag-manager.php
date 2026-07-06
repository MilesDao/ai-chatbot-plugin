<?php
/**
 * Class AI_Chatbot_Manager
 *
 * Manages document ingestion, chunking, database storage,
 * and vector-based Cosine Similarity searching.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI_Chatbot_Manager {

    /**
     * Parse and chunk a file, then request embeddings and save to DB.
     *
     * @param int $doc_id Document ID from {wp_prefix}ai_chatbot_documents.
     * @param string $file_path Local path to the uploaded file.
     * @param string $file_name Original filename.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function process_and_index_document( $doc_id, $file_path, $file_name ) {
        global $wpdb;

        $api_key = get_option( 'ai_chatbot_openrouter_api_key', '' );
        if ( empty( $api_key ) ) {
            return new WP_Error( 'missing_api_key', 'Please supply an OpenRouter API Key in the settings first.' );
        }

        // 1. Extract raw text based on file type
        $ext = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
        $text = '';

        if ( 'txt' === $ext ) {
            $text = file_get_contents( $file_path );
        } elseif ( 'pdf' === $ext ) {
            $text = self::extract_text_from_pdf( $file_path );
        } else {
            return new WP_Error( 'unsupported_format', 'Unsupported file format. Only .txt and .pdf are supported currently.' );
        }

        if ( empty( trim( $text ) ) ) {
            return new WP_Error( 'empty_document', 'No readable text could be extracted from the uploaded document.' );
        }

        // 2. Chunk the text
        $chunk_size    = intval( get_option( 'ai_chatbot_chunk_size', '1000' ) );
        $chunk_overlap = intval( get_option( 'ai_chatbot_chunk_overlap', '200' ) );
        $chunks        = $this->chunk_text( $text, $chunk_size, $chunk_overlap );

        if ( empty( $chunks ) ) {
            return new WP_Error( 'chunking_failed', 'Failed to chunk the document contents.' );
        }

        // 3. Generate embeddings and save to database
        $chat_model = get_option( 'ai_chatbot_openrouter_model', 'openai/gpt-oss-120b:free' );
        $embed_model = get_option( 'ai_chatbot_openrouter_embed_model', 'nvidia/llama-nemotron-embed-vl-1b-v2:free' );
        $client = new OpenRouter_API_Client( $api_key, $chat_model, $embed_model );
        $table_chunks = $wpdb->prefix . 'ai_chatbot_chunks';

        $successful_chunks = 0;
        $pinecone_vectors = array();
        
        foreach ( $chunks as $chunk_content ) {
            // Trim and clean chunk content
            $chunk_content = trim( $chunk_content );
            if ( empty( $chunk_content ) ) {
                continue;
            }

            // Get embedding vector (array of 768 floats)
            $embedding = $client->get_embedding( $chunk_content );

            if ( is_wp_error( $embedding ) ) {
                // If one chunk fails, log and continue, or fail entirely depending on strictness.
                // We'll log to WordPress error log and try to continue so partial results aren't lost.
                error_log( 'AI Chatbot Indexing Error: ' . $embedding->get_error_message() );
                continue;
            }

            // Save chunk in DB
            $inserted = $wpdb->insert(
                $table_chunks,
                array(
                    'document_id' => $doc_id,
                    'content'     => $chunk_content,
                    'embedding'   => wp_json_encode( $embedding ),
                    'token_count' => intval( strlen( $chunk_content ) / 4 ) // rough estimate of tokens
                ),
                array( '%d', '%s', '%s', '%d' )
            );

            if ( $inserted ) {
                $chunk_id = $wpdb->insert_id;
                $successful_chunks++;
                
                // Prepare vector for Pinecone
                $pinecone_vectors[] = array(
                    'id'     => strval( $chunk_id ),
                    'values' => $embedding,
                    'metadata' => array(
                        'document_id' => $doc_id,
                        'content'     => $chunk_content
                    )
                );
            }
        }

        // Push to Pinecone if configured
        if ( ! empty( $pinecone_vectors ) ) {
            $pinecone_result = $this->pinecone_upsert( $pinecone_vectors );
            if ( is_wp_error( $pinecone_result ) ) {
                return $pinecone_result; // Return the exact error so UI can display it
            }
        }

        // Update document status & actual chunk count in the DB
        $table_docs = $wpdb->prefix . 'ai_chatbot_documents';
        $wpdb->update(
            $table_docs,
            array(
                'chunk_count' => $successful_chunks,
                'status'      => 'indexed'
            ),
            array( 'id' => $doc_id ),
            array( '%d', '%s' ),
            array( '%d' )
        );

        if ( 0 === $successful_chunks ) {
            return new WP_Error( 'indexing_failed', 'Failed to generate embeddings for all chunks of the document. Please verify your API Key.' );
        }

        return true;
    }

    /**
     * Upsert vectors to Pinecone
     * 
     * @param array $vectors Array of vectors to upsert
     * @return bool|WP_Error
     */
    private function pinecone_upsert( $vectors ) {
        $api_key = get_option( 'ai_chatbot_pinecone_api_key', '' );
        $host = get_option( 'ai_chatbot_pinecone_host', '' );

        if ( empty( $api_key ) || empty( $host ) ) {
            return false;
        }

        $host = rtrim( $host, '/' );
        $url = $host . '/vectors/upsert';

        $body = array(
            'vectors' => $vectors,
            'namespace' => ''
        );

        $response = wp_remote_post( $url, array(
            'headers' => array(
                'Api-Key'      => $api_key,
                'Content-Type' => 'application/json'
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 30
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'Pinecone Upsert Error: ' . $response->get_error_message() );
            return $response;
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            $body_str = wp_remote_retrieve_body( $response );
            $body_data = json_decode( $body_str, true );
            $error_msg = isset( $body_data['message'] ) ? $body_data['message'] : ( isset( $body_data['error'] ) ? json_encode( $body_data['error'] ) : 'Pinecone API Error' );
            error_log( 'Pinecone Upsert Failed. Status: ' . $status . ' Body: ' . $body_str );
            return new WP_Error( 'pinecone_error', 'Lỗi Pinecone (HTTP ' . $status . '): ' . $error_msg );
        }

        return true;
    }

    /**
     * Splits long text into overlapping chunks, snapping to nearest word boundaries.
     *
     * @param string $text Raw text.
     * @param int $chunk_size Maximum character size of a chunk.
     * @param int $chunk_overlap Character overlap.
     * @return array List of text chunks.
     */
    private function chunk_text( $text, $chunk_size = 1000, $chunk_overlap = 200 ) {
        // Clean white spaces but preserve single newlines
        $text = preg_replace( '/[ \t]+/u', ' ', $text );
        
        // Split text by double newlines or periods to maintain semantics
        $parts = preg_split('/(\n\n|\.)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        
        $sentences = array();
        $temp = "";
        foreach ( $parts as $part ) {
            $temp .= $part;
            if ( $part === "\n\n" || $part === "." ) {
                if ( ! empty( trim( $temp ) ) ) {
                    $sentences[] = trim( $temp ) . ( $part === "." ? "" : "" );
                }
                $temp = "";
            }
        }
        if ( ! empty( trim( $temp ) ) ) {
            $sentences[] = trim( $temp );
        }
        
        $chunks = array();
        $current_chunk = "";
        
        foreach ( $sentences as $sentence ) {
            if ( empty( $current_chunk ) ) {
                $current_chunk = $sentence;
            } elseif ( mb_strlen( $current_chunk ) + mb_strlen( $sentence ) + 1 <= $chunk_size ) {
                $current_chunk .= " " . $sentence;
            } else {
                $chunks[] = $current_chunk;
                $current_chunk = $sentence;
            }
        }
        
        if ( ! empty( $current_chunk ) ) {
            $chunks[] = $current_chunk;
        }
        
        // If no chunks generated (e.g. extremely long text without dots), fallback
        if ( empty( $chunks ) ) {
            $chunks[] = mb_substr( $text, 0, $chunk_size );
        }
        
        return $chunks;
    }

    /**
     * Perform local similarity search using vector Cosine Similarity.
     *
     * @param string $query User query text.
     * @param int $k Number of chunks to return.
     * @return array Array of associative arrays containing chunks: [ 'content', 'document_name', 'similarity' ]
     */
    public function search_similar_chunks( $query, $k = 3, $threshold = 0.75 ) {
        global $wpdb;

        $api_key = get_option( 'ai_chatbot_openrouter_api_key', '' );
        if ( empty( $api_key ) ) {
            return array();
        }

        // 1. Generate embedding for query
        $chat_model = get_option( 'ai_chatbot_openrouter_model', 'openai/gpt-oss-120b:free' );
        $embed_model = get_option( 'ai_chatbot_openrouter_embed_model', 'nvidia/llama-nemotron-embed-vl-1b-v2:free' );
        $client = new OpenRouter_API_Client( $api_key, $chat_model, $embed_model );
        $query_vector = $client->get_embedding( $query );

        if ( is_wp_error( $query_vector ) || empty( $query_vector ) ) {
            return array();
        }

        // Check if Pinecone is configured
        $pinecone_api_key = get_option( 'ai_chatbot_pinecone_api_key', '' );
        $pinecone_host    = get_option( 'ai_chatbot_pinecone_host', '' );
        
        $scored_chunks = array();

        if ( ! empty( $pinecone_api_key ) && ! empty( $pinecone_host ) ) {
            // Pinecone Search
            $host = rtrim( $pinecone_host, '/' );
            $url = $host . '/query';

            $body = array(
                'vector' => $query_vector,
                'topK'   => $k,
                'includeMetadata' => true
            );

            $response = wp_remote_post( $url, array(
                'headers' => array(
                    'Api-Key'      => $pinecone_api_key,
                    'Content-Type' => 'application/json'
                ),
                'body'    => wp_json_encode( $body ),
                'timeout' => 15
            ) );

            if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
                $body_data = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( ! empty( $body_data['matches'] ) ) {
                    // Pre-fetch document names
                    $doc_ids = array();
                    foreach ( $body_data['matches'] as $match ) {
                        if ( ! empty( $match['metadata']['document_id'] ) ) {
                            $doc_ids[] = intval( $match['metadata']['document_id'] );
                        }
                    }
                    $doc_names = array();
                    if ( ! empty( $doc_ids ) ) {
                        $doc_ids_str = implode( ',', array_unique( $doc_ids ) );
                        $table_docs = $wpdb->prefix . 'ai_chatbot_documents';
                        $results = $wpdb->get_results( "SELECT id, name FROM $table_docs WHERE id IN ($doc_ids_str)" );
                        foreach ( $results as $row ) {
                            $doc_names[ $row->id ] = $row->name;
                        }
                    }

                    foreach ( $body_data['matches'] as $match ) {
                        if ( $match['score'] >= $threshold ) {
                            $doc_id = $match['metadata']['document_id'] ?? 0;
                            $scored_chunks[] = array(
                                'content'       => $match['metadata']['content'] ?? '',
                                'document_name' => $doc_names[ $doc_id ] ?? 'Tài liệu',
                                'similarity'    => $match['score']
                            );
                        }
                    }
                    return $scored_chunks; // Already sorted by Pinecone
                }
            }
        }

        // Fallback to local DB Search
        // 2. Fetch all chunks from db
        $table_chunks = $wpdb->prefix . 'ai_chatbot_chunks';
        $table_docs   = $wpdb->prefix . 'ai_chatbot_documents';

        $query_db = "
            SELECT c.content, c.embedding, d.name as document_name 
            FROM $table_chunks c
            JOIN $table_docs d ON c.document_id = d.id
            WHERE d.status = 'indexed'
        ";

        $db_chunks = $wpdb->get_results( $query_db, ARRAY_A );
        if ( empty( $db_chunks ) ) {
            return array();
        }

        // 3. Compute cosine similarity for each chunk
        foreach ( $db_chunks as $row ) {
            $chunk_vector = json_decode( $row['embedding'], true );
            
            if ( ! is_array( $chunk_vector ) || count( $chunk_vector ) !== count( $query_vector ) ) {
                continue;
            }

            $score = $this->cosine_similarity( $query_vector, $chunk_vector );
            
            if ( $score >= $threshold ) {
                $scored_chunks[] = array(
                    'content'       => $row['content'],
                    'document_name' => $row['document_name'],
                    'similarity'    => $score
                );
            }
        }

        // 4. Sort by score descending
        usort( $scored_chunks, function( $a, $b ) {
            if ( $a['similarity'] == $b['similarity'] ) {
                return 0;
            }
            return ( $a['similarity'] > $b['similarity'] ) ? -1 : 1;
        } );

        // 5. Return top K
        return array_slice( $scored_chunks, 0, $k );
    }

    /**
     * Calculates the Cosine Similarity between two floating-point vector arrays.
     */
    private function cosine_similarity( $vec1, $vec2 ) {
        $dot_product = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;
        $count = count( $vec1 );

        for ( $i = 0; $i < $count; $i++ ) {
            $dot_product += $vec1[$i] * $vec2[$i];
            $norm1 += $vec1[$i] * $vec1[$i];
            $norm2 += $vec2[$i] * $vec2[$i];
        }

        if ( $norm1 == 0.0 || $norm2 == 0.0 ) {
            return 0.0;
        }

        return $dot_product / ( sqrt( $norm1 ) * sqrt( $norm2 ) );
    }

    /**
     * Highly robust pure PHP PDF Text Extractor.
     * Parses standard compressed streams (FlateDecode) and extracts raw Tj/TJ content.
     */
    public static function extract_text_from_pdf( $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            return '';
        }

        $content = file_get_contents( $file_path );
        if ( ! $content ) {
            return '';
        }

        $text = '';
        
        // Find all object blocks in the PDF
        preg_match_all( "/\s*(\d+\s+\d+\s+obj.*?.?endobj)/s", $content, $objects );
        
        if ( ! empty( $objects[1] ) ) {
            foreach ( $objects[1] as $object ) {
                // If it is a compressed stream using FlateDecode
                if ( preg_match( "/\/Filter\s*\/FlateDecode/i", $object ) && preg_match( "/stream\r?\n(.*?)\r?\nendstream/is", $object, $stream ) ) {
                    $data = $stream[1];
                    // Decompress the stream
                    $decompressed = @gzuncompress( $data );
                    if ( ! $decompressed ) {
                        // Attempt inflate if zlib header is slightly offset or omitted
                        $decompressed = @gzinflate( substr( $data, 2, -4 ) );
                    }
                    if ( $decompressed ) {
                        // 1. Tj matches
                        preg_match_all( "/\((.*?)\)\s*Tj/is", $decompressed, $tj_matches );
                        foreach ( $tj_matches[1] as $chunk ) {
                            $text .= self::clean_pdf_string( $chunk ) . ' ';
                        }
                        
                        // 2. TJ matches (bracketed array of strings and positioning offsets)
                        preg_match_all( "/\[(.*?)\]\s*TJ/is", $decompressed, $tj_brackets );
                        foreach ( $tj_brackets[1] as $bracket_contents ) {
                            preg_match_all( "/\((.*?)\)/is", $bracket_contents, $nested_strings );
                            foreach ( $nested_strings[1] as $chunk ) {
                                $text .= self::clean_pdf_string( $chunk ) . ' ';
                            }
                        }
                    }
                } else {
                    // Extract uncompressed text streams Tj/TJ
                    preg_match_all( "/\((.*?)\)\s*Tj/is", $object, $tj_matches );
                    foreach ( $tj_matches[1] as $chunk ) {
                        $text .= self::clean_pdf_string( $chunk ) . ' ';
                    }
                }
            }
        } else {
            // Fallback: search for brackets in raw file if PDF structure is corrupted
            preg_match_all( "/\((.*?)\)/", $content, $fallback_matches );
            foreach ( $fallback_matches[1] as $chunk ) {
                $text .= self::clean_pdf_string( $chunk ) . ' ';
            }
        }

        // Clean up whitespaces
        $text = preg_replace( '/\s+/u', ' ', $text );
        return trim( $text );
    }

    /**
     * Helper to clean up PDF escape codes and return plain UTF-8.
     */
    private static function clean_pdf_string( $str ) {
        // PDF strings backslash escapes
        $str = str_replace( 
            array( '\\(', '\\)', '\\\\', '\\n', '\\r', '\\t' ), 
            array( '(', ')', '\\', "\n", "\r", "\t" ), 
            $str 
        );
        // Remove non-printable / structural junk characters often present in binary streams
        return preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $str );
    }
}
