<?php

declare(strict_types=1);

namespace Bluebranch\Chatbot\Security;

use Contao\BackendUser;

class ChatbotPermissions
{
    /**
     * Check if the user has permission to create single alt descriptions
     */
    public static function canCreateSingle(): bool
    {
        return self::hasPermission('create_single');
    }

    /**
     * Helper method to check if user has a specific permission
     */
    private static function hasPermission(string $permission): bool
    {
        $user = BackendUser::getInstance();

        // No user context (e.g. console commands)
        if (!$user instanceof BackendUser) {
            return false;
        }

        // Check if user object has required properties
        if (!isset($user->admin)) {
            return false;
        }

        // Admins always have permission
        if ($user->admin) {
            return true;
        }

        return false;
    }
}
