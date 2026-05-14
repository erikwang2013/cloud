<?php
namespace App\Ticket\Model;

use Illuminate\Database\Eloquent\Model;
use App\User\Model\User;
use App\Provisioning\Model\Resource;

class Ticket extends Model
{
    protected $table = 'tickets';
    protected $fillable = [
        'ticket_no', 'user_id', 'resource_id', 'category',
        'priority', 'title', 'status', 'assigned_to',
        'sla_deadline', 'closed_by', 'closed_at',
    ];

    protected $casts = [
        'sla_deadline' => 'datetime',
        'closed_at'    => 'datetime',
    ];

    public function messages()
    {
        return $this->hasMany(TicketMessage::class)->orderBy('created_at');
    }

    public function latestMessage()
    {
        return $this->hasOne(TicketMessage::class)->latestOfMany();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }
}
