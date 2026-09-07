<x-layouts.app :title="($member->display_name ?? $member->username).' — Member profile'">
    <livewire:member-profile :member="$member" :stats="$stats" />
</x-layouts.app>
