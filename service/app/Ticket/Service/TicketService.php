<?php
namespace App\Ticket\Service;

use App\Ticket\Model\Ticket;
use App\Ticket\Model\TicketMessage;
use App\Ticket\Event\TicketCreated;

class TicketService
{
    private array $slaMinutes = [
        'urgent'  => 30,
        'high'    => 120,
        'normal'  => 480,
        'low'     => 1440,
    ];

    public function create(int $userId, array $data): Ticket
    {
        $ticket = Ticket::create([
            'ticket_no'    => 'TK' . date('YmdHis') . rand(100, 999),
            'user_id'      => $userId,
            'resource_id'  => $data['resource_id'] ?? null,
            'category'     => $data['category'],
            'priority'     => $data['priority'] ?? 'normal',
            'title'        => $data['title'],
            'status'       => 'open',
            'sla_deadline' => date('Y-m-d H:i:s', time() + $this->slaMinutes[$data['priority'] ?? 'normal'] * 60),
        ]);

        TicketMessage::create([
            'ticket_id'   => $ticket->id,
            'sender_id'   => $userId,
            'sender_type' => 'user',
            'content'     => $data['content'],
        ]);

        if (class_exists(\Illuminate\Support\Facades\Event::class)) {
            \Illuminate\Support\Facades\Event::dispatch(new TicketCreated($ticket));
        }

        return $ticket->load('messages');
    }

    public function reply(int $ticketId, int $senderId, string $senderType, string $content): TicketMessage
    {
        $ticket = Ticket::findOrFail($ticketId);

        if ($ticket->status === 'closed') {
            throw new \InvalidArgumentException('Ticket is closed');
        }

        if ($ticket->status === 'on_hold' && $senderType === 'user') {
            $ticket->update(['status' => 'open']);
        }

        if ($senderType === 'staff' && $ticket->status === 'open') {
            $ticket->update([
                'status'      => 'in_progress',
                'assigned_to' => $senderId,
            ]);
        }

        return TicketMessage::create([
            'ticket_id'   => $ticketId,
            'sender_id'   => $senderId,
            'sender_type' => $senderType,
            'content'     => $content,
        ]);
    }

    public function close(int $ticketId, int $staffId): void
    {
        Ticket::where('id', $ticketId)->update([
            'status'     => 'closed',
            'closed_by'  => $staffId,
            'closed_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function assignTicket(int $ticketId, int $staffId): void
    {
        Ticket::where('id', $ticketId)->update(['assigned_to' => $staffId]);
    }

    public function autoAssign(Ticket $ticket): void
    {
        $supportStaff = \App\User\Model\User::where('role', 'support')
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        if ($supportStaff->isEmpty()) return;

        $bestStaff = $supportStaff->sortBy(function ($staff) {
            return Ticket::where('assigned_to', $staff->id)
                ->whereIn('status', ['open', 'in_progress'])
                ->count();
        })->first();

        $ticket->update(['assigned_to' => $bestStaff->id]);
    }
}
