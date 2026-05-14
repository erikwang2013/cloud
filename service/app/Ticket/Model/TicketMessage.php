<?php
namespace App\Ticket\Model;

use Illuminate\Database\Eloquent\Model;

class TicketMessage extends Model
{
    protected $table = 'ticket_messages';
    protected $fillable = [
        'ticket_id', 'sender_id', 'sender_type', 'content',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }
}
