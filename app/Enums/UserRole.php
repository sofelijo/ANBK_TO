<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Teacher = 'teacher';
    case Student = 'student';
}
