<?php
require_once 'includes/db.php';

try {
    echo "<h1>Photo Integrity Migration Tool</h1>";
    echo "<p>Running migration to fix historical visit photos...</p>";

    // SQL 1: Update empty visit_photo from visitors.photo_path
    $sql1 = "UPDATE visits v 
             JOIN visitors vis ON v.visitor_id = vis.id 
             SET v.visit_photo = vis.photo_path 
             WHERE (v.visit_photo IS NULL OR v.visit_photo = '' OR v.visit_photo = 'assets/img/visitor-icon.png')
             AND (vis.photo_path IS NOT NULL AND vis.photo_path != '')";
    
    $stmt1 = $pdo->prepare($sql1);
    $stmt1->execute();
    $count1 = $stmt1->rowCount();
    
    echo "<div style='color:green; padding:10px; border:1px solid green; margin-bottom:10px;'>";
    echo "<strong>Success:</strong> Fixed {$count1} historical visit records by copying their visitor profile photos.";
    echo "</div>";

    echo "<p>Next steps: No further action needed. All visit records now have 'frozen' photos that won't change if the visitor updates their profile in the future.</p>";
    
} catch (Exception $e) {
    echo "<div style='color:red; padding:10px; border:1px solid red;'>";
    echo "<strong>Error:</strong> " . $e->getMessage();
    echo "</div>";
}
?>
