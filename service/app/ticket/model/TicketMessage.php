<?php
namespace App\ticket\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class TicketMessage extends Model
{
    use HasSnowflakeId;
    protected $table = 'ticket_messages';
    protected $fillable = [
        'ticket_id', 'sender_id', 'sender_type', 'content',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }
}
