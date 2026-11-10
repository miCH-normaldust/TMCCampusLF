<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') - TMC CampusLF Admin</title>
    @vite('resources/css/app.css')
</head>
<body>
<div class="shell">
    <aside class="side">
        <div class="brand">
            <span class="brand-emblems" aria-hidden="true">
                <img class="tmc-brand-logo" src="{{ asset('images/tmc-logo.png') }}" alt="">
                <img class="campuslf-brand-logo" src="{{ asset('images/campuslf-logo.png') }}" alt="">
            </span>
            <button class="drawer-toggle" type="button" aria-label="Collapse navigation" aria-expanded="true" title="Collapse navigation">‹</button>
            <span class="nav-label">TMC CampusLF</span>
        </div>
        <nav class="nav" aria-label="Admin navigation">
            @php
                $pinnedNavigation = [
                    ['label' => 'Dashboard', 'icon' => '⌂', 'route' => 'admin.dashboard', 'active' => 'admin.dashboard'],
                    ['label' => 'Community Feed', 'icon' => '▤', 'route' => 'admin.community-feed.index', 'active' => 'admin.community-feed.*'],
                ];
                $monitoringLinks = [
                        ['label' => 'Users', 'icon' => '♙', 'route' => 'admin.users.index', 'active' => 'admin.users.*'],
                        ['label' => 'Reports', 'icon' => '⚑', 'route' => 'admin.reports.index', 'active' => 'admin.reports.*', 'count' => 'reports'],
                        ['label' => 'Chats', 'icon' => '▣', 'route' => 'admin.chats.index', 'active' => 'admin.chats.*'],
                        ['label' => 'Feedback', 'icon' => '✉', 'route' => 'admin.feedback.index', 'active' => 'admin.feedback.*', 'count' => 'feedback'],
                        ['label' => 'Audit Trail', 'icon' => '▣', 'route' => 'admin.audit.index', 'active' => 'admin.audit.*'],
                        ['label' => 'Settings', 'icon' => '⚙', 'route' => 'admin.settings.index', 'active' => 'admin.settings.*'],
                ];
            @endphp
            @foreach ($pinnedNavigation as $item)
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
            <a href="{{ route('admin.activity.index') }}"
               class="nav-link {{ request()->routeIs('admin.activity.*') ? 'active' : '' }}"
               title="Activity" aria-label="Activity"
               @if (request()->routeIs('admin.activity.*')) aria-current="page" @endif>
                <span class="nav-icon" aria-hidden="true">◷</span>
                <span class="nav-label">Activity</span>
            </a>
            <details class="nav-dropdown" @if (request()->routeIs('admin.users.*', 'admin.reports.*', 'admin.chats.*', 'admin.feedback.*', 'admin.audit.*', 'admin.settings.*')) open @endif>
                <summary class="nav-link" title="Monitoring and Maintenance" aria-label="Monitoring and Maintenance">
                    <span class="nav-icon" aria-hidden="true">⚙</span>
                    <span class="nav-label">Monitoring and Maintenance</span>
                </summary>
                <div class="nav-dropdown-links">
                @foreach ($monitoringLinks as $item)
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
                </div>
            </details>
        </nav>
    </aside>
    <div class="content">
        <header class="top">
            <div class="top-title">
                <button class="mobile-drawer-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false" title="Toggle navigation">☰</button>
                <strong>Administration</strong>
            </div>
            <div class="top-actions">
                <form method="post" action="{{ route('admin.logout') }}">@csrf<button class="button secondary" type="submit">Log out</button></form>
            </div>
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
        document.querySelectorAll('form[data-auto-filter]').forEach(form => {
            let searchTimer;
            const submit = () => {
                window.clearTimeout(searchTimer);
                form.requestSubmit();
            };

            form.querySelectorAll('select, input[type="date"]').forEach(control => {
                control.addEventListener('change', submit);
            });
            form.querySelectorAll('input[name="search"]').forEach(input => {
                input.addEventListener('input', () => {
                    window.clearTimeout(searchTimer);
                    searchTimer = window.setTimeout(() => form.requestSubmit(), 300);
                });
            });
        });
    })();
</script>
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
