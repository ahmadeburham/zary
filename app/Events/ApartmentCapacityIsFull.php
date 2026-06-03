<?php

namespace App\Events;

use App\Models\Apartment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApartmentCapacityIsFull
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public Apartment $apartment)
    {
    }
}
