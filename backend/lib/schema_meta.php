<?php
// تولید خودکار توسط tools/gen_schema_meta.php — دستی ویرایش نکنید.
return array (
  'auth_users' => 
  array (
    'cols' => 
    array (
      'id' => 'str',
      'email' => 'str',
      'password_hash' => 'str',
      'banned_until' => 'ts',
      'created_at' => 'ts',
      'last_login_at' => 'ts',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => true,
  ),
  'sessions' => 
  array (
    'cols' => 
    array (
      'token_hash' => 'str',
      'user_id' => 'str',
      'created_at' => 'ts',
      'expires_at' => 'ts',
      'last_seen' => 'ts',
      'ua' => 'str',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'token_hash',
    ),
    'uuid_pk' => false,
  ),
  'profiles' => 
  array (
    'cols' => 
    array (
      'id' => 'str',
      'full_name' => 'str',
      'role' => 'str',
      'specialties' => 'json',
      'notify' => 'bool',
      'created_at' => 'ts',
      'avatar_url' => 'str',
      'avatar_original_url' => 'str',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => true,
  ),
  'prompts' => 
  array (
    'cols' => 
    array (
      'id' => 'str',
      'name' => 'str',
      'version' => 'int',
      'model' => 'str',
      'body' => 'str',
      'is_active' => 'bool',
      'created_by' => 'str',
      'created_at' => 'ts',
      'active_key' => 'int',
    ),
    'hidden' => 
    array (
      0 => 'active_key',
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => true,
  ),
  'documents' => 
  array (
    'cols' => 
    array (
      'id' => 'str',
      'title' => 'str',
      'source_filename' => 'str',
      'source_path' => 'str',
      'status' => 'str',
      'word_count' => 'int',
      'specialty' => 'str',
      'uploaded_by' => 'str',
      'reviewed_by' => 'str',
      'claimed_by' => 'str',
      'claimed_at' => 'ts',
      'claim_expires_at' => 'ts',
      'supervisor_id' => 'str',
      'prompt_id' => 'str',
      'ai_model' => 'str',
      'ai_started_at' => 'ts',
      'ai_finished_at' => 'ts',
      'ai_error' => 'str',
      'glossary' => 'json',
      'ai_notes' => 'json',
      'review_note' => 'str',
      'qa_note' => 'str',
      'created_at' => 'ts',
      'updated_at' => 'ts',
      'priority' => 'int',
      'priority_by' => 'str',
      'priority_at' => 'ts',
      'source_kind' => 'str',
      'replaced_by' => 'str',
      'source_url' => 'str',
      'source_name' => 'str',
      'source_published_at' => 'ts',
      'editor_note' => 'str',
      'news_edited_by' => 'str',
      'news_edited_at' => 'ts',
      'ai_review_state' => 'str',
      'ai_review_error' => 'str',
      'ai_review_at' => 'ts',
      'senior_only' => 'bool',
      'published_by' => 'str',
      'published_at' => 'ts',
      'publish_title' => 'str',
      'wp_post_id' => 'int',
      'wp_url' => 'str',
      'wp_status' => 'str',
      'wp_error' => 'str',
      'wp_sent_at' => 'ts',
      'unpublish_note' => 'str',
      'pub_slug' => 'str',
      'pub_excerpt' => 'str',
      'pub_tags' => 'json',
      'pub_category' => 'int',
      'pub_seo_title' => 'str',
      'pub_seo_desc' => 'str',
      'pub_seo_focus' => 'str',
      'pub_at' => 'ts',
      'news_score' => 'int',
      'news_score_reason' => 'str',
      'news_curated_at' => 'ts',
      'pub_author' => 'str',
      'pub_author_id' => 'int',
      'source_url_key' => 'str',
    ),
    'hidden' => 
    array (
      0 => 'source_url_key',
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => true,
  ),
  'segments' => 
  array (
    'cols' => 
    array (
      'id' => 'str',
      'document_id' => 'str',
      'position' => 'int',
      'kind' => 'str',
      'level' => 'int',
      'source_text' => 'str',
      'ai_text' => 'str',
      'current_text' => 'str',
      'flagged' => 'bool',
      'seconds_spent' => 'int',
      'last_edited_by' => 'str',
      'last_edited_at' => 'ts',
      'ai_review_text' => 'str',
      'ai_review_note' => 'str',
      'ai_review_round' => 'int',
      'ai_review_at' => 'ts',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => true,
  ),
  'segment_revisions' => 
  array (
    'cols' => 
    array (
      'id' => 'int',
      'segment_id' => 'str',
      'document_id' => 'str',
      'revision_no' => 'int',
      'text' => 'str',
      'source' => 'str',
      'author_id' => 'str',
      'created_at' => 'ts',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => false,
  ),
  'segment_comments' => 
  array (
    'cols' => 
    array (
      'id' => 'str',
      'segment_id' => 'str',
      'document_id' => 'str',
      'author_id' => 'str',
      'body' => 'str',
      'resolved' => 'bool',
      'created_at' => 'ts',
      'batch_id' => 'str',
      'batch_lead' => 'bool',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => true,
  ),
  'notifications' => 
  array (
    'cols' => 
    array (
      'id' => 'str',
      'user_id' => 'str',
      'type' => 'str',
      'document_id' => 'str',
      'payload' => 'json',
      'read_at' => 'ts',
      'created_at' => 'ts',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => true,
  ),
  'document_events' => 
  array (
    'cols' => 
    array (
      'id' => 'int',
      'document_id' => 'str',
      'actor_id' => 'str',
      'from_status' => 'str',
      'to_status' => 'str',
      'created_at' => 'ts',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => false,
  ),
  'priority_history' => 
  array (
    'cols' => 
    array (
      'id' => 'int',
      'document_id' => 'str',
      'priority' => 'int',
      'changed_by' => 'str',
      'changed_at' => 'ts',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => false,
  ),
  'app_config' => 
  array (
    'cols' => 
    array (
      'key' => 'str',
      'value' => 'str',
      'updated_at' => 'ts',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'key',
    ),
    'uuid_pk' => false,
  ),
  'app_secrets' => 
  array (
    'cols' => 
    array (
      'name' => 'str',
      'value' => 'str',
      'updated_by' => 'str',
      'updated_at' => 'ts',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'name',
    ),
    'uuid_pk' => false,
  ),
  'invites' => 
  array (
    'cols' => 
    array (
      'id' => 'str',
      'token' => 'str',
      'role' => 'str',
      'label' => 'str',
      'created_by' => 'str',
      'created_at' => 'ts',
      'expires_at' => 'ts',
      'max_uses' => 'int',
      'uses' => 'int',
      'revoked' => 'bool',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => true,
  ),
  'profile_contacts' => 
  array (
    'cols' => 
    array (
      'id' => 'str',
      'phone' => 'str',
      'email' => 'str',
      'first_name' => 'str',
      'last_name' => 'str',
      'invite_id' => 'str',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => true,
  ),
  'glossary' => 
  array (
    'cols' => 
    array (
      'id' => 'str',
      'source_term' => 'str',
      'target_term' => 'str',
      'note' => 'str',
      'is_active' => 'bool',
      'created_by' => 'str',
      'created_at' => 'ts',
      'updated_at' => 'ts',
      'source_key' => 'str',
    ),
    'hidden' => 
    array (
      0 => 'source_key',
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => true,
  ),
  'news_sources' => 
  array (
    'cols' => 
    array (
      'id' => 'str',
      'name' => 'str',
      'kind' => 'str',
      'feed_url' => 'str',
      'link_pattern' => 'str',
      'body_selector' => 'str',
      'check_every_min' => 'int',
      'is_active' => 'bool',
      'last_checked_at' => 'ts',
      'last_ok_at' => 'ts',
      'last_error' => 'str',
      'created_at' => 'ts',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => true,
  ),
  'news_rules' => 
  array (
    'cols' => 
    array (
      'id' => 'str',
      'source_id' => 'str',
      'kind' => 'str',
      'keyword' => 'str',
      'is_active' => 'bool',
      'created_at' => 'ts',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => true,
  ),
  'news_items' => 
  array (
    'cols' => 
    array (
      'id' => 'str',
      'source_id' => 'str',
      'url' => 'str',
      'url_hash' => 'str',
      'title' => 'str',
      'status' => 'str',
      'reason' => 'str',
      'document_id' => 'str',
      'published_at' => 'ts',
      'fetched_at' => 'ts',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => true,
  ),
  'translator_levels' => 
  array (
    'cols' => 
    array (
      'user_id' => 'str',
      'level' => 'str',
      'updated_at' => 'ts',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'user_id',
    ),
    'uuid_pk' => false,
  ),
  'document_images' => 
  array (
    'cols' => 
    array (
      'id' => 'str',
      'document_id' => 'str',
      'path' => 'str',
      'filename' => 'str',
      'mime' => 'str',
      'size_bytes' => 'int',
      'kind' => 'str',
      'alt_text' => 'str',
      'caption' => 'str',
      'after_position' => 'int',
      'uploaded_by' => 'str',
      'approved' => 'bool',
      'approved_by' => 'str',
      'approved_at' => 'ts',
      'wp_media_id' => 'int',
      'created_at' => 'ts',
      'wp_url' => 'str',
      'featured_flag' => 'int',
    ),
    'hidden' => 
    array (
      0 => 'featured_flag',
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => true,
  ),
  'push_subscriptions' => 
  array (
    'cols' => 
    array (
      'id' => 'str',
      'user_id' => 'str',
      'endpoint' => 'str',
      'endpoint_key' => 'str',
      'p256dh' => 'str',
      'auth' => 'str',
      'ua' => 'str',
      'created_at' => 'ts',
    ),
    'hidden' => 
    array (
      0 => 'endpoint_key',
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => true,
  ),
  'jobs' => 
  array (
    'cols' => 
    array (
      'id' => 'int',
      'kind' => 'str',
      'ref_id' => 'str',
      'payload' => 'json',
      'state' => 'str',
      'attempts' => 'int',
      'run_after' => 'ts',
      'locked_until' => 'ts',
      'progress' => 'json',
      'error' => 'str',
      'created_at' => 'ts',
      'updated_at' => 'ts',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => false,
  ),
  'login_attempts' => 
  array (
    'cols' => 
    array (
      'id' => 'int',
      'ip' => 'str',
      'email' => 'str',
      'created_at' => 'ts',
    ),
    'hidden' => 
    array (
    ),
    'pk' => 
    array (
      0 => 'id',
    ),
    'uuid_pk' => false,
  ),
);
