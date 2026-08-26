<?php
namespace App\product\model;

enum ReviewStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
