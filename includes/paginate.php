<?php
declare(strict_types=1);

const LIST_PAGE_LIMITS = [25, 50, 75, 100];
const LIST_PAGE_PARAMS = ['page', 'credit_page', 'debit_page', 'fixed_page', 'regular_page'];

function list_page_limit(): int
{
    $limit = (int) get('limit', 25);
    return in_array($limit, LIST_PAGE_LIMITS, true) ? $limit : 25;
}

function list_limit_hidden(): string
{
    return '<input type="hidden" name="limit" value="' . (int) list_page_limit() . '">';
}

function list_preserved_query(array $extra = [], array $skip = []): array
{
    $skip = array_flip($skip);
    $out = [];
    foreach ($_GET as $k => $v) {
        $key = (string) $k;
        if (isset($skip[$key]) || is_array($v)) {
            continue;
        }
        $out[$key] = (string) $v;
    }
    foreach ($extra as $k => $v) {
        if ($v === null || $v === '') {
            unset($out[$k]);
        } else {
            $out[$k] = (string) $v;
        }
    }
    $out['limit'] = (string) list_page_limit();
    return $out;
}

function list_return_url(string $pageFile, array $extra = [], string $anchor = 'list'): string
{
    $query = list_preserved_query($extra, ['action', 'id']);
    unset($query['action'], $query['id']);
    $qs = http_build_query(array_filter($query, static fn($v) => $v !== null && $v !== ''));
    $hash = $anchor !== '' ? '#' . $anchor : '';
    return 'pages/' . $pageFile . ($qs !== '' ? '?' . $qs : '') . $hash;
}

/**
 * @return array{total: int, page: int, pages: int, limit: int, offset: int, from: int, to: int, page_param: string}
 */
function paginate_meta(int $total, string $pageParam = 'page', ?int $limit = null): array
{
    $limit = $limit ?? list_page_limit();
    $pages = max(1, (int) ceil($total / max(1, $limit)));
    $page = min(max(1, (int) get($pageParam, 1)), $pages);
    $offset = ($page - 1) * $limit;
    return [
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'limit' => $limit,
        'offset' => $offset,
        'from' => $total > 0 ? $offset + 1 : 0,
        'to' => min($offset + $limit, $total),
        'page_param' => $pageParam,
    ];
}

/**
 * @return array{rows: list<mixed>, total: int, page: int, pages: int, limit: int, offset: int, from: int, to: int, page_param: string}
 */
function paginate_list(array $rows, ?int $limit = null, string $pageParam = 'page'): array
{
    $p = paginate_meta(count($rows), $pageParam, $limit);
    $slice = array_slice($rows, $p['offset'], $p['limit']);
    $p['rows'] = $slice;
    $count = count($slice);
    $p['from'] = $count ? $p['offset'] + 1 : 0;
    $p['to'] = $p['offset'] + $count;
    return $p;
}

function render_limit_control(string $pageFile, array $hidden = [], string $anchor = 'list'): void
{
    $limit = list_page_limit();
    $params = list_preserved_query($hidden, LIST_PAGE_PARAMS);
    unset($params['limit']);
    $action = base_url('pages/' . $pageFile);
    if ($anchor !== '') {
        $action .= '#' . $anchor;
    }
    echo '<form method="get" class="limit-control" action="' . e($action) . '">';
    foreach ($params as $name => $value) {
        echo '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
    }
    echo '<label class="muted" style="margin:0;font-size:0.78rem;white-space:nowrap">Show</label>';
    echo '<select name="limit" onchange="this.form.submit()" aria-label="Rows per page">';
    foreach (LIST_PAGE_LIMITS as $opt) {
        $sel = $limit === $opt ? ' selected' : '';
        echo '<option value="' . $opt . '"' . $sel . '>' . $opt . '</option>';
    }
    echo '</select>';
    echo '<span class="muted" style="font-size:0.78rem;white-space:nowrap">per page</span>';
    echo '</form>';
}

function render_pager(string $pageFile, array $p, string $anchor = 'list'): void
{
    if ((int) ($p['total'] ?? 0) < 1) {
        return;
    }
    $pageParam = (string) ($p['page_param'] ?? 'page');
    $query = list_preserved_query();
    $urlFor = static function (int $pg) use ($pageFile, $query, $pageParam, $anchor): string {
        $query[$pageParam] = (string) $pg;
        $qs = http_build_query(array_filter($query, static fn($v) => $v !== null && $v !== ''));
        $hash = $anchor !== '' ? '#' . $anchor : '';
        return base_url('pages/' . $pageFile . ($qs !== '' ? '?' . $qs : '') . $hash);
    };
    $page = (int) $p['page'];
    $pages = (int) $p['pages'];
    echo '<div class="pager">';
    if ($page > 1) {
        echo '<a class="btn btn-outline btn-sm" href="' . e($urlFor($page - 1)) . '">← Prev</a>';
    } else {
        echo '<span class="btn btn-outline btn-sm" aria-disabled="true">← Prev</span>';
    }
    echo '<span class="pager-info">Showing ' . (int) $p['from'] . '–' . (int) $p['to'] . ' of ' . (int) $p['total']
        . ' · Page ' . $page . ' of ' . $pages . '</span>';
    if ($page < $pages) {
        echo '<a class="btn btn-outline btn-sm" href="' . e($urlFor($page + 1)) . '">Next →</a>';
    } else {
        echo '<span class="btn btn-outline btn-sm" aria-disabled="true">Next →</span>';
    }
    echo '</div>';
}
