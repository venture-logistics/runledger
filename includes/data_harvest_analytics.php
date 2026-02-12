<?php
/**
 * Data Harvest Analytics - Called by data_harvest.bat
 * Generates weekly PDF report
 */

require_once __DIR__ . '/db.php';

// Check if TCPDF exists, if not provide instructions
if (!file_exists(__DIR__ . '/../vendor/tcpdf/tcpdf.php')) {
    echo "ERROR: TCPDF library not found.\n";
    echo "Download from: https://github.com/tecnickcom/TCPDF/archive/refs/heads/main.zip\n";
    echo "Extract to: vendor/tcpdf/\n";
    exit(1);
}

require_once __DIR__ . '/../vendor/tcpdf/tcpdf.php';

class LedgerAnalytics {
    private $db;
    private $pdf;
    
    public function __construct() {
        $this->db = getDB();
        $this->initPDF();
    }
    
    private function initPDF() {
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $this->pdf->SetCreator('Ledger');
        $this->pdf->SetTitle('Weekly Analytics - ' . date('Y-m-d'));
        $this->pdf->SetHeaderData('', 0, 'Ledger Analytics Report', date('F j, Y'));
        $this->pdf->setHeaderFont(['helvetica', '', 11]);
        $this->pdf->setFooterFont(['helvetica', '', 8]);
        $this->pdf->SetMargins(15, 27, 15);
        $this->pdf->SetAutoPageBreak(true, 25);
    }
    
    public function generate() {
        $this->pdf->AddPage();
        
        // Summary
        $this->section('Executive Summary');
        $summary = $this->getSummary();
        $this->text("Users: {$summary['users']}");
        $this->text("Forum Topics: {$summary['topics']}");
        $this->text("Forum Posts: {$summary['posts']}");
        $this->text("Knowledge Docs: {$summary['docs']}");
        $this->pdf->Ln(5);
        
        // Users
        $this->section('User Statistics');
        $users = $this->getUserStats();
        foreach ($users['by_role'] as $r) {
            $this->text("  {$r['role']}: {$r['count']}");
        }
        $this->pdf->Ln(3);
        $this->subsection('Forum Designations');
        foreach ($users['by_designation'] as $d) {
            $this->text("  {$d['forum_designation']}: {$d['count']}");
        }
        $this->pdf->Ln(5);
        
        // Forum
        $this->section('Forum Activity');
        $forum = $this->getForumStats();
        $this->text("Topics: {$forum['topics']}");
        $this->text("Posts: {$forum['posts']}");
        $this->text("Pinned: {$forum['pinned']} | Locked: {$forum['locked']}");
        $this->pdf->Ln(3);
        $this->subsection('Topics by Category');
        foreach ($forum['by_category'] as $c) {
            $this->text("  {$c['name']}: {$c['count']}");
        }
        $this->pdf->Ln(5);
        
        // Knowledge
        $this->section('Knowledge Base');
        $kb = $this->getKnowledgeStats();
        $this->text("Documents: {$kb['docs']}");
        $avgRating = number_format($kb['avg_rating'], 2);
        $this->text("Avg Rating: {$avgRating}/5 ({$kb['ratings']} votes)");
        $this->text("Flagged: {$kb['flagged']}");
        $this->pdf->Ln(3);
        $this->subsection('Docs by Category');
        foreach ($kb['by_category'] as $c) {
            $this->text("  {$c['name']}: {$c['count']}");
        }
        $this->pdf->Ln(5);
        
        // Engagement
        $this->section('Engagement');
        $eng = $this->getEngagement();
        $this->text("Total Helpful Votes: {$eng['helpful_votes']}");
        if (!empty($eng['top_contributors'])) {
            $this->pdf->Ln(3);
            $this->subsection('Top Contributors');
            foreach ($eng['top_contributors'] as $tc) {
                $this->text("  {$tc['name']}: {$tc['helpful']} votes ({$tc['posts']} posts)");
            }
        }
        $this->pdf->Ln(5);
        
        // Downloads
        $dl = $this->getDownloads();
        if ($dl['total'] > 0) {
            $this->section('Downloads');
            $this->text("Total: {$dl['total']}");
            $this->pdf->Ln(3);
            foreach ($dl['items'] as $item) {
                $this->text("  {$item['slug']}: {$item['downloads']}");
            }
        }
        
        // Save
        $filename = __DIR__ . '/../reports/analytics_' . date('Y-m-d_H-i-s') . '.pdf';
        $this->pdf->Output($filename, 'F');
        echo "Analytics PDF: " . basename($filename) . "\n";
    }
    
    private function getSummary() {
        return [
            'users' => $this->db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'topics' => $this->db->query("SELECT COUNT(*) FROM forum_topics WHERE is_deleted=0")->fetchColumn(),
            'posts' => $this->db->query("SELECT COUNT(*) FROM forum_posts WHERE is_deleted=0")->fetchColumn(),
            'docs' => $this->db->query("SELECT COUNT(*) FROM knowledge_documents WHERE is_published=1")->fetchColumn()
        ];
    }
    
    private function getUserStats() {
        return [
            'by_role' => $this->db->query("SELECT role, COUNT(*) as count FROM users GROUP BY role")->fetchAll(),
            'by_designation' => $this->db->query("SELECT forum_designation, COUNT(*) as count FROM users GROUP BY forum_designation ORDER BY count DESC")->fetchAll()
        ];
    }
    
    private function getForumStats() {
        return [
            'topics' => $this->db->query("SELECT COUNT(*) FROM forum_topics WHERE is_deleted=0")->fetchColumn(),
            'posts' => $this->db->query("SELECT COUNT(*) FROM forum_posts WHERE is_deleted=0")->fetchColumn(),
            'pinned' => $this->db->query("SELECT COUNT(*) FROM forum_topics WHERE is_pinned=1 AND is_deleted=0")->fetchColumn(),
            'locked' => $this->db->query("SELECT COUNT(*) FROM forum_topics WHERE is_locked=1 AND is_deleted=0")->fetchColumn(),
            'by_category' => $this->db->query("
                SELECT fc.name, COUNT(ft.id) as count
                FROM forum_categories fc
                LEFT JOIN forum_topics ft ON fc.id=ft.category_id AND ft.is_deleted=0
                GROUP BY fc.id, fc.name
                ORDER BY count DESC
            ")->fetchAll()
        ];
    }
    
    private function getKnowledgeStats() {
        $rating = $this->db->query("SELECT AVG(rating) as avg, COUNT(*) as cnt FROM knowledge_ratings")->fetch();
        return [
            'docs' => $this->db->query("SELECT COUNT(*) FROM knowledge_documents WHERE is_published=1")->fetchColumn(),
            'avg_rating' => $rating['avg'] ?? 0,
            'ratings' => $rating['cnt'],
            'flagged' => $this->db->query("SELECT COUNT(DISTINCT document_id) FROM knowledge_flags")->fetchColumn(),
            'by_category' => $this->db->query("
                SELECT kc.name, COUNT(kd.id) as count
                FROM knowledge_categories kc
                LEFT JOIN knowledge_documents kd ON kc.id=kd.category_id AND kd.is_published=1
                WHERE kc.is_active=1
                GROUP BY kc.id, kc.name
                ORDER BY count DESC
            ")->fetchAll()
        ];
    }
    
    private function getEngagement() {
        return [
            'helpful_votes' => $this->db->query("SELECT COUNT(*) FROM forum_post_helpful")->fetchColumn(),
            'top_contributors' => $this->db->query("
                SELECT 
                    fp.created_by_name as name,
                    COUNT(DISTINCT fph.id) as helpful,
                    COUNT(DISTINCT fp.id) as posts
                FROM forum_posts fp
                LEFT JOIN forum_post_helpful fph ON fp.id=fph.post_id
                WHERE fp.is_deleted=0 AND fp.created_by_name IS NOT NULL
                GROUP BY fp.created_by_name
                HAVING helpful > 0
                ORDER BY helpful DESC
                LIMIT 5
            ")->fetchAll()
        ];
    }
    
    private function getDownloads() {
        $items = $this->db->query("SELECT slug, downloads FROM download_stats ORDER BY downloads DESC LIMIT 10")->fetchAll();
        return [
            'total' => array_sum(array_column($items, 'downloads')),
            'items' => $items
        ];
    }
    
    private function section($title) {
        $this->pdf->SetFont('helvetica', 'B', 14);
        $this->pdf->Cell(0, 10, $title, 0, 1);
        $this->pdf->SetFont('helvetica', '', 10);
    }
    
    private function subsection($title) {
        $this->pdf->SetFont('helvetica', 'B', 11);
        $this->pdf->Cell(0, 6, $title, 0, 1);
        $this->pdf->SetFont('helvetica', '', 10);
    }
    
    private function text($text) {
        $this->pdf->Cell(0, 5, $text, 0, 1);
    }
}

try {
    $analytics = new LedgerAnalytics();
    $analytics->generate();
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
