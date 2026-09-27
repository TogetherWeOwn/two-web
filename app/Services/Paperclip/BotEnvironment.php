<?php

namespace App\Services\Paperclip;

/**
 * Which two-bot deployment a restart card targets, and its Coolify application.
 *
 * The uuids are pinned from the operator provisioning card (TOG-3573), read from
 * the Coolify API on the controller. They are identifiers, not secrets: the
 * deploy-scope token that can act on them lives only with whoever runs the
 * restart. Which one this two-web instance targets is server config
 * (PAPERCLIP_RESTART_BOT_ENVIRONMENT), so staging can never file a card that
 * restarts production.
 */
enum BotEnvironment: string
{
    case Staging = 'staging';
    case Production = 'production';

    public function coolifyAppUuid(): string
    {
        return match ($this) {
            self::Staging => 'uy4d9ndeygjcem6lgayhxgub',
            self::Production => 'cangagerae31txrk2vfvzzyq',
        };
    }
}
