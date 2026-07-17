<?php

// Intentionally inert. Laravel Scout was evaluated for search and removed:
// portfolio search needs to reach inside JSONB columns (reflections, email
// payloads), which Scout's database driver cannot do. Search is plain
// Postgres ILIKE in App\Http\Controllers\SearchController. Delete this file
// once the sandbox allows removing files from config/.
return [];
