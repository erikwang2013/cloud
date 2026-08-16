<?php
namespace App\Product\Model;

enum ReviewStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
