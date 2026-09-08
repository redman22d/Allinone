<?php
namespace TutorInstructorOfflinePayment;
class Orders {
    public static function can_view($order, $user) { return false; }
    public static function __callStatic($name, $args) { throw new \RuntimeException('Unauthorized order read: ' . $name); }
}
