<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScraperProfile extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'platform',
        'profile_key',
        'profile_label',
        'browser_profile_path',
        'cookie_file_path',
        'persistent_profile_enabled',
        'headless_enabled',
        'auto_login_enabled',
        'login_username',
        'login_password_encrypted',
        'login_password_base_encrypted',
        'navigation_timeout_seconds',
        'post_login_wait_ms',
        'typing_delay_ms',
        'relationship_list_process_timeout_seconds',
        'relationship_list_max_scroll_rounds',
        'follower_list_max_items',
        'following_list_max_items',
        'is_primary',
        'is_active',
        'sort_order',
        'cookie_payload',
        'cookie_payload_hash',
        'cookie_count',
        'session_cookie_present',
        'cookies_synced_at',
        'metadata',
    ];

    protected $casts = [
        'persistent_profile_enabled' => 'boolean',
        'headless_enabled' => 'boolean',
        'auto_login_enabled' => 'boolean',
        'navigation_timeout_seconds' => 'integer',
        'post_login_wait_ms' => 'integer',
        'typing_delay_ms' => 'integer',
        'relationship_list_process_timeout_seconds' => 'integer',
        'relationship_list_max_scroll_rounds' => 'integer',
        'follower_list_max_items' => 'integer',
        'following_list_max_items' => 'integer',
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'cookie_count' => 'integer',
        'session_cookie_present' => 'boolean',
        'cookies_synced_at' => 'datetime',
        'metadata' => 'array',
    ];
}
