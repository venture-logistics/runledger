</main>
<?php
// Latest posts (last 5)
$latest = [];
$stmt = $conn->prepare("
  SELECT p.id, p.created_at, t.slug AS topic_slug, t.title AS topic_title
  FROM forum_posts p
  JOIN forum_topics t ON t.id = p.topic_id
  WHERE 1=1
  ORDER BY p.created_at DESC
  LIMIT 15
");
if ($stmt) {
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) $latest[] = $r;
  $stmt->close();
}

// Top helpful (top 5 posts)
$top = [];
$stmt = $conn->prepare("
  SELECT p.id, p.helpful_count, t.slug AS topic_slug, t.title AS topic_title
  FROM forum_posts p
  JOIN forum_topics t ON t.id = p.topic_id
  WHERE p.helpful_count > 0
  ORDER BY p.helpful_count DESC, p.created_at DESC
  LIMIT 15
");
if ($stmt) {
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) $top[] = $r;
  $stmt->close();
}

function site_desc(): string {
    global $site;
    return h($site['site_description'] ?? '');
}

$topDocs = [];

$minRatings = 3;     // don’t rank docs with <3 ratings
$limit = 5;          // how many links in footer

$stmt = $conn->prepare("
  SELECT
    d.title,
    d.slug,
    ROUND(AVG(r.rating), 1) AS avg_rating,
    COUNT(*) AS rating_count
  FROM knowledge_documents d
  JOIN knowledge_ratings r ON r.document_id = d.id
  WHERE d.is_published = 1
  GROUP BY d.id
  HAVING COUNT(*) >= ?
  ORDER BY avg_rating DESC, rating_count DESC
  LIMIT ?
");
$stmt->bind_param("ii", $minRatings, $limit);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $topDocs[] = $row;
$stmt->close();

?>

<style>
/* CKEditor code block styling */
.ck-content pre {
  background: #0f172a;        /* dark slate */
  color: #e5e7eb;
  padding: 1rem;
  border-radius: 6px;
  overflow-x: auto;
  font-size: 0.9rem;
  line-height: 1.5;
  margin: 1rem 0;
}

.ck-content pre code {
  background: none;
  color: inherit;
  padding: 0;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

/* Optional: inline code */
.ck-content code {
  background: #f1f3f5;
  padding: 2px 6px;
  border-radius: 6px;
}

pre {
    background-color: #272822; /* Dark theme background */
    color: #f8f8f2;
    padding: 15px;
    border-radius: 5px;
    overflow-x: auto;
    margin: 1em 0;
}

code {
    font-family: 'Courier New', Courier, monospace;
    font-size: 0.9em;
}
</style>

<footer class="mt-5 border-top" style="background:#f8f9fa;">
  <div class="container py-5">
    <div class="row gy-4">

      <!-- Column 1: Identity / disclaimer -->
      <div class="col-lg-3 pe-lg-5">
        <div class="d-flex align-items-center mb-2">
          <img src="<?= h($site['logo_bottom']) ?>" alt="PGAT" width="34" height="34" class="me-2" style="opacity:.85">
          <div>
            <div class="fw-semibold" style="letter-spacing:.2px;"><?= h($site['site_name']) ?></div>
          </div>
        </div>
        <p class="small text-muted mb-0" style="line-height:1.5;">
          <?= site_desc() ?>
        </p>
      </div>

      <!-- Column 2: Latest activity -->
      <div class="col-lg-3">
        <div class="fw-semibold mb-2">Latest activity</div>

        <?php if (!$latest): ?>
          <div class="small text-muted">No posts yet.</div>
        <?php else: ?>
          <ul class="list-unstyled small mb-0">
            <?php foreach ($latest as $r): ?>
              <li class="mb-2">
                <a class="link-secondary text-decoration-none" href="/topic.php?t=<?= h($r['topic_slug']) ?>#post-<?= (int)$r['id'] ?>">
                  <?= h(mb_substr($r['topic_title'], 0, 60)) ?><?= (mb_strlen($r['topic_title']) > 60 ? '…' : '') ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <!-- Column 3: Top helpful -->
      <div class="col-lg-3">
        <div class="fw-semibold mb-2">Forum helpful</div>

        <?php if (!$top): ?>
          <div class="small text-muted">No helpful votes yet.</div>
        <?php else: ?>
          <ul class="list-unstyled small mb-0">
            <?php foreach ($top as $r): ?>
              <li class="mb-2 d-flex justify-content-between gap-3">
                <a class="link-secondary text-decoration-none" href="/topic.php?t=<?= h($r['topic_slug']) ?>#post-<?= (int)$r['id'] ?>">
                  <?= h(mb_substr($r['topic_title'], 0, 55)) ?><?= (mb_strlen($r['topic_title']) > 55 ? '…' : '') ?>
                </a>
                <span class="badge text-bg-success align-self-start"><?= (int)$r['helpful_count'] ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

        <!-- Column 4: Top knowledge -->
        <div class="col-lg-3">
          <div class="fw-semibold mb-2">Top knowledge</div>
        
          <?php if (!$topDocs): ?>
            <div class="small text-muted">No rated documents yet.</div>
          <?php else: ?>
            <ul class="list-unstyled small mb-0">
              <?php foreach ($topDocs as $d): ?>
                <li class="mb-2 d-flex justify-content-between gap-3">
                  <a class="link-secondary text-decoration-none"
                     href="/knowledge_document.php?doc=<?= h($d['slug']) ?>">
                    <?= h(mb_substr($d['title'], 0, 55)) ?><?= (mb_strlen($d['title']) > 55 ? '…' : '') ?>
                  </a>
        
                  <span class="badge text-bg-warning align-self-start">
                    <?= h($d['avg_rating']) ?>★
                  </span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

    </div>
  </div>

  <!-- Bottom copyright bar -->
  <?php if (!empty($site['footer_bottom'] ?? '')): ?>
<div class="border-top py-3">
  <div class="container">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 text-center text-md-start">

      <!-- Left -->
      <div class="fw-semibold fs-6">
        © <?= date('Y') ?> <?= nl2br(h($site['footer_bottom'])) ?>
      </div>

      <!-- Right -->
      <div class="small text-muted">
        Powered by
        <a target="_blank" href="https://runledger.org/knowledge_document.php?doc=knowledge-ledger-manifesto" class="text-decoration-none">
          Ledger
        </a>
      </div>

    </div>
  </div>
</div>

  <?php endif; ?>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.ckeditor.com/ckeditor5/41.4.2/super-build/ckeditor.js"></script>
<script>
function SimpleUploadAdapterPlugin(editor) {
  editor.plugins.get('FileRepository').createUploadAdapter = (loader) => {
    return {
      upload: async () => {
        const file = await loader.file;
        const data = new FormData();
        data.append('upload', file);

        const res = await fetch('/includes/upload_image.php', { method: 'POST', body: data });
        const json = await res.json();

        if (!json || !json.url) throw new Error('Upload failed');
        return { default: json.url };
      }
    };
  };
}    

document.querySelectorAll('textarea[data-ckeditor="1"]').forEach((el) => {
    CKEDITOR.ClassicEditor.create(el, {
        licenseKey: 'GPL', // Essential for free use in 2026
        
        // 1. Add your language configuration here
        codeBlock: {
            languages: [
                // The first item is the DEFAULT
                { language: 'html', label: 'HTML' }, 
                { language: 'javascript', label: 'JavaScript' },
                { language: 'css', label: 'CSS' },
                { language: 'php', label: 'PHP' },
                { language: 'plaintext', label: 'Plain text' }
            ]
        },        
 
        removePlugins: [
            // Fixes the latest license errors
            'Pagination',
            'SlashCommand',
            'CaseChange',
            'FormatPainter',
            'ExportPdf',
            'ExportWord',
            'ImportWord',
            'Template',
            'PasteFromOfficeEnhanced',
            'Mention',
            'Emoji',            
            
            // Fixes previous license/configuration errors
            'MultiLevelList', 
            'DocumentOutline',
            'TableOfContents',
            'WProofreader', 
            'AIAssistant',
            'CKBox',
            'CKFinder',
            'EasyImage',
            'RealTimeCollaborationClient',
            'RealTimeCollaborativeComments',
            'RealTimeCollaborativeTrackChanges',
            'RealTimeCollaborativeRevisionHistory',
            'PresenceList',
            'Comments',
            'CommentsRepository',
            'TrackChanges',
            'TrackChangesData',
            'RevisionHistory',
            'WideSidebar'
        ],

        toolbar: {
            items: [
                'heading', '|',
                'bold', 'italic', 'link', 'bulletedList', 'numberedList', '|',
                'horizontalLine', // creates <hr>
                'strikethrough', 
                '|',
                'code',        // inline code
                'codeBlock',   // block code
                '|',
                'blockQuote', '|',
                'undo', 'redo', '|',
                'insertImageViaUrl',
            ],
            
            
        shouldNotGroupWhenFull: true
        }
    })
    .catch(err => {
        console.error('CKEditor failed to load:', err);
    });
});



</script>

<dialog id="imgdlg" onclick="this.close()">
  <img id="imgdlgimg" style="max-width:95vw;max-height:95vh">
</dialog>

<script>
document.addEventListener('click', e => {
  const a = e.target.closest('a');
  if (!a || !/\.(png|jpe?g|webp|gif)(\?.*)?$/i.test(a.href)) return;
  e.preventDefault();
  imgdlgimg.src = a.href;
  imgdlg.showModal();
});
</script>
</body>
</html>