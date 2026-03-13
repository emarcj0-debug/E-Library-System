<?php
/**
 * isbn_lookup.php — AJAX endpoint
 * Fetches book metadata from the Open Library API by ISBN.
 *
 * Usage:  GET isbn_lookup.php?isbn=978-0-13-110362-7
 * Returns JSON: { success, data: { title, author, cover_image, description, publisher, pub_year, category } }
 */

header('Content-Type: application/json');

$isbn = trim($_GET['isbn'] ?? '');
$isbn = preg_replace('/[^0-9X\-]/i', '', $isbn);          // keep digits, X, dashes
$isbnClean = preg_replace('/[\-\s]/', '', $isbn);           // strip dashes for API

if ($isbnClean === '' || (strlen($isbnClean) !== 10 && strlen($isbnClean) !== 13)) {
    echo json_encode(['success' => false, 'error' => 'Please enter a valid 10- or 13-digit ISBN.']);
    exit;
}

/* ---------- 1. Try Open Library Books API ---------- */
$apiUrl = "https://openlibrary.org/api/books?bibkeys=ISBN:{$isbnClean}&format=json&jscmd=data";
$response = @file_get_contents($apiUrl);

if ($response === false) {
    echo json_encode(['success' => false, 'error' => 'Could not reach Open Library. Check server internet access & allow_url_fopen.']);
    exit;
}

$json = json_decode($response, true);
$key  = "ISBN:{$isbnClean}";

if (empty($json[$key])) {
    /* ---------- 2. Fallback: Open Library Search API ---------- */
    $searchUrl = "https://openlibrary.org/search.json?isbn={$isbnClean}&limit=1";
    $searchResp = @file_get_contents($searchUrl);
    if ($searchResp !== false) {
        $searchJson = json_decode($searchResp, true);
        if (!empty($searchJson['docs'][0])) {
            $doc = $searchJson['docs'][0];
            $coverId = $doc['cover_i'] ?? null;
            echo json_encode([
                'success' => true,
                'data'    => [
                    'title'       => $doc['title'] ?? '',
                    'author'      => isset($doc['author_name']) ? implode(', ', $doc['author_name']) : '',
                    'cover_image' => $coverId ? "https://covers.openlibrary.org/b/id/{$coverId}-L.jpg" : '',
                    'description' => '',
                    'publisher'   => isset($doc['publisher']) ? ($doc['publisher'][0] ?? '') : '',
                    'pub_year'    => (string)($doc['first_publish_year'] ?? ''),
                    'category'    => isset($doc['subject']) ? ($doc['subject'][0] ?? 'General') : 'General',
                ]
            ]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'error' => 'ISBN not found in Open Library.']);
    exit;
}

$book = $json[$key];

/* Gather fields */
$title     = $book['title'] ?? '';
$authors   = [];
if (!empty($book['authors'])) {
    foreach ($book['authors'] as $a) {
        $authors[] = $a['name'] ?? '';
    }
}
$author = implode(', ', $authors);

$coverImg  = '';
if (!empty($book['cover']['large'])) {
    $coverImg = $book['cover']['large'];
} elseif (!empty($book['cover']['medium'])) {
    $coverImg = $book['cover']['medium'];
}

$description = '';
if (!empty($book['excerpts'][0]['text'])) {
    $description = $book['excerpts'][0]['text'];
} elseif (!empty($book['notes'])) {
    $description = is_array($book['notes']) ? ($book['notes']['value'] ?? '') : $book['notes'];
}

$publisher = '';
if (!empty($book['publishers'][0]['name'])) {
    $publisher = $book['publishers'][0]['name'];
}

$pubYear = '';
if (!empty($book['publish_date'])) {
    // try to extract 4-digit year
    if (preg_match('/(\d{4})/', $book['publish_date'], $m)) {
        $pubYear = $m[1];
    } else {
        $pubYear = $book['publish_date'];
    }
}

$category = 'General';
if (!empty($book['subjects'])) {
    $category = $book['subjects'][0]['name'] ?? 'General';
}

echo json_encode([
    'success' => true,
    'data'    => [
        'title'       => $title,
        'author'      => $author,
        'cover_image' => $coverImg,
        'description' => $description,
        'publisher'   => $publisher,
        'pub_year'    => $pubYear,
        'category'    => $category,
    ]
]);
