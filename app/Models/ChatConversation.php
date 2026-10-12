<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A chat: between two people, or a group (a department, a course this semester, a study group, or any group). */
class ChatConversation extends Model
{
    public const TYPES = ['direct' => 'Direct', 'department' => 'Department', 'course' => 'Course', 'study' => 'Study group', 'group' => 'Group'];

    protected $fillable = ['type', 'title', 'description', 'direct_key', 'department_id', 'course_id', 'academic_session_id', 'semester_type_id',
        'created_by', 'is_open', 'only_admins_post', 'last_message_at'];

    protected $casts = ['is_open' => 'boolean', 'only_admins_post' => 'boolean', 'last_message_at' => 'datetime'];

    public function members(): HasMany
    {
        return $this->hasMany(ChatMember::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function latestMessage(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ChatMessage::class)->latestOfMany();
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function isGroup(): bool
    {
        return $this->type !== 'direct';
    }

    /** Groups the portal keeps (department, course): people are added and removed automatically. */
    public function isAutomatic(): bool
    {
        return in_array($this->type, ['department', 'course'], true);
    }
}
