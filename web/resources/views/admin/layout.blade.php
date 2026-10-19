<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') - TMC CampusLF Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
</head>
<body>
<div class="shell">
    <aside class="side">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true">LF</span>
            <button class="drawer-toggle" type="button" aria-label="Collapse navigation" aria-expanded="true" title="Collapse navigation">‹</button>
            <span class="nav-label">TMC CampusLF</span>
        </div>
        <nav class="nav" aria-label="Admin navigation">
            @php
                $navigation = [
                    ['label' => 'Dashboard', 'icon' => '⌂', 'route' => 'admin.dashboard', 'active' => 'admin.dashboard'],
                    ['label' => 'Community Feed', 'icon' => '▤', 'route' => 'admin.community-feed.index', 'active' => 'admin.community-feed.*'],
                    ['label' => 'Found Item Claims', 'icon' => '✓', 'route' => 'admin.claims.index', 'active' => 'admin.claims.*', 'count' => 'claims'],
                    ['label' => 'Lost Item Proofs', 'icon' => '⌕', 'route' => 'admin.sightings.index', 'active' => 'admin.sightings.*', 'count' => 'sightings'],
                    ['label' => 'Handovers', 'icon' => '⇄', 'route' => 'admin.handovers.index', 'active' => 'admin.handovers.*', 'count' => 'handovers'],
                    ['label' => 'Users', 'icon' => '♙', 'route' => 'admin.users.index', 'active' => 'admin.users.*'],
                    ['label' => 'Activity', 'icon' => '◷', 'route' => 'admin.activity.index', 'active' => 'admin.activity.*'],
                    ['label' => 'Audit Trail', 'icon' => '▣', 'route' => 'admin.audit.index', 'active' => 'admin.audit.*'],
                    ['label' => 'Reports', 'icon' => '⚑', 'route' => 'admin.reports.index', 'active' => 'admin.reports.*', 'count' => 'reports'],
                    ['label' => 'Settings', 'icon' => '⚙', 'route' => 'admin.settings.index', 'active' => 'admin.settings.*'],
                ];
            @endphp
            @foreach ($navigation as $item)
                <a href="{{ route($item['route']) }}"
                   class="nav-link {{ request()->routeIs($item['active']) ? 'active' : '' }}"
                   title="{{ $item['label'] }}"
                   aria-label="{{ $item['label'] }}"
                   @if (request()->routeIs($item['active'])) aria-current="page" @endif>
                    <span class="nav-icon" aria-hidden="true">{{ $item['icon'] }}</span>
                    <span class="nav-label">{{ $item['label'] }}</span>
                    @if (isset($item['count']) && ($adminNavCounts[$item['count']] ?? 0) > 0)
                        <span class="nav-count" aria-label="{{ $adminNavCounts[$item['count']] }} pending">{{ $adminNavCounts[$item['count']] }}</span>
                    @endif
                </a>
            @endforeach
        </nav>
    </aside>
    <div class="content">
        <header class="top">
            <div class="top-title">
                <button class="mobile-drawer-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false" title="Toggle navigation">☰</button>
                <strong>Administration</strong>
            </div>
            <form method="post" action="{{ route('admin.logout') }}">@csrf<button class="button secondary" type="submit">Log out</button></form>
        </header>
        <main>
            @if (session('status'))<div class="flash" role="status">{{ session('status') }}</div>@endif
            @if ($errors->any())<div class="flash error-flash" role="alert">{{ $errors->first() }}</div>@endif
            @yield('content')
        </main>
    </div>
</div>
<script>
    (() => {
        const shell = document.querySelector('.shell');
        const toggle = document.querySelector('.drawer-toggle');
        const mobileToggle = document.querySelector('.mobile-drawer-toggle');
        if (!shell || !toggle || !mobileToggle) return;

        if (localStorage.getItem('campuslf-admin-drawer') === 'collapsed') {
            shell.classList.add('drawer-collapsed');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-label', 'Expand navigation');
            toggle.title = 'Expand navigation';
            toggle.textContent = '›';
        }
        toggle.addEventListener('click', () => {
            const collapsed = shell.classList.toggle('drawer-collapsed');
            localStorage.setItem('campuslf-admin-drawer', collapsed ? 'collapsed' : 'expanded');
            toggle.setAttribute('aria-expanded', String(!collapsed));
            toggle.setAttribute('aria-label', collapsed ? 'Expand navigation' : 'Collapse navigation');
            toggle.title = collapsed ? 'Expand navigation' : 'Collapse navigation';
            toggle.textContent = collapsed ? '›' : '‹';
        });
        mobileToggle.addEventListener('click', () => {
            const open = shell.classList.toggle('drawer-open');
            mobileToggle.setAttribute('aria-expanded', String(open));
        });
        shell.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', () => {
                shell.classList.remove('drawer-open');
                mobileToggle.setAttribute('aria-expanded', 'false');
            });
        });
        document.addEventListener('click', event => {
            if (
                shell.classList.contains('drawer-open') &&
                event.target instanceof Element &&
                !event.target.closest('.side') &&
                !event.target.closest('.mobile-drawer-toggle')
            ) {
                shell.classList.remove('drawer-open');
                mobileToggle.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                shell.classList.remove('drawer-open');
                mobileToggle.setAttribute('aria-expanded', 'false');
            }
        });
    })();
</script>
</body>
</html>
