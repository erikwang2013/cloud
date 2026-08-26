<?php

namespace Tests\ticket;

use App\ticket\event\TicketCreated;
use App\ticket\event\TicketStatusChanged;
use App\ticket\model\Ticket;
use App\websocket\listener\TicketUpdatedListener;
use PHPUnit\Framework\TestCase;

final class TicketUpdatedWiringTest extends TestCase
{
    private array $events;

    protected function setUp(): void
    {
        $this->events = require __DIR__ . '/../../config/event.php';
    }

    public function testTicketUpdatedListenerWiredToStatusChangeNotCreation(): void
    {
        $this->assertContains(
            TicketUpdatedListener::class,
            $this->events[TicketStatusChanged::class] ?? []
        );
        $this->assertNotContains(
            TicketUpdatedListener::class,
            $this->events[TicketCreated::class] ?? []
        );
    }

    public function testTicketCreatedStillWiredToAutoAssign(): void
    {
        $this->assertContains(
            \App\ticket\listener\AutoAssignListener::class,
            $this->events[TicketCreated::class] ?? []
        );
    }

    public function testStatusChangedEventCarriesTicket(): void
    {
        $ticket = new Ticket(['title' => 'Cannot connect', 'status' => 'closed']);
        $ticket->id = 42;
        $event  = new TicketStatusChanged($ticket);

        $this->assertSame(42, $event->ticket->id);
        $this->assertSame('closed', $event->ticket->status);
        $this->assertSame('Cannot connect', $event->ticket->title);
    }
}
