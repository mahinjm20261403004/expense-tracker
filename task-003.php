<?php
// api_orchestrator.php

header("Content-Type: text/event-stream");
header("Cache-Control: no-cache");

// Database Configuration
$host = 'localhost';
$db   = 'expense_tracker_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    echo "data: [ERROR]\n\n";
    echo "data: {$e->getMessage()}\n\n";
    exit;
}

// Get Workflow ID from query string or session (Simulating authenticated request)
$workflowId = isset($_GET['id']) ? intval($_GET['id']) : 1; 

// Helper to log events
function logEvent($pdo, $workflowId, $stageId, $action, $message, $status = 'success', $retryCount = 0) {
    $stmt = $pdo->prepare("INSERT INTO workflow_logs (workflow_id, stage_id, action, message, status, retry_count) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$workflowId, $stageId, $action, $message, $status, $retryCount]);
}

// Helper to get current state
function getState($pdo, $workflowId) {
    $stmt = $pdo->prepare("SELECT * FROM workflows WHERE id = ?");
    $stmt->execute([$workflowId]);
    return $stmt->fetch();
}

function getStage($pdo, $workflowId, $idx) {
    $stmt = $pdo->prepare("SELECT * FROM workflow_stages WHERE workflow_id = ? AND (stage_order = ? OR id IN (SELECT id FROM workflow_stages WHERE workflow_id = ? ORDER BY stage_order LIMIT ?))");
    // Simplified logic: fetch all stages and pick by index or ID
    $stmt = $pdo->prepare("SELECT * FROM workflow_stages WHERE workflow_id = ? ORDER BY id LIMIT 1 OFFSET ?");
    $stmt->execute([$workflowId, $idx]);
    return $stmt->fetch();
}

// --- ORCHESTRATION LOGIC ---

$workflow = getState($pdo, $workflowId);

if (!$workflow) {
    echo "data: [ERROR]\n\n";
    echo "data: Workflow not found.\n\n";
    exit;
}

// 1. Check if workflow is paused (Checkpoint)
if ($workflow['status'] === 'paused') {
    // Wait for approval/rejection logic would go here in a real async loop.
    // For this demo, we check for an immediate POST request to resume.
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action']; // 'approve' or 'reject'
        
        if ($action === 'approve') {
            logEvent($pdo, $workflowId, null, 'resume', 'Workflow resumed by user.', 'success');
            $workflow['status'] = 'running';
            $stmt = $pdo->prepare("UPDATE workflows SET status = ? WHERE id = ?");
            $stmt->execute([$workflow['status'], $workflowId]);
        } elseif ($action === 'reject') {
            logEvent($pdo, $workflowId, null, 'cancel', 'Workflow cancelled by user.', 'failed');
            $workflow['status'] = 'completed'; // Final state
            $stmt = $pdo->prepare("UPDATE workflows SET status = ? WHERE id = ?");
            $stmt->execute([$workflow['status'], $workflowId]);
        }
    }
}

// 2. Main Execution Loop (Simulated Async)
if ($workflow['status'] === 'running') {
    // Get current stage index based on logs or stored state
    // For simplicity, we assume sequential execution from the last log entry
    
    $stmt = $pdo->prepare("SELECT * FROM workflow_logs WHERE workflow_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$workflowId]);
    $lastLog = $stmt->fetch();

    if ($lastLog) {
        $currentStageIdx = $lastLog['stage_id']; // Using ID as proxy for index in this simple schema
        
        // Fetch next stage
        $stages = [];
        $stmt = $pdo->prepare("SELECT * FROM workflow_stages WHERE workflow_id = ? ORDER BY id");
        $stmt->execute([$workflowId]);
        $stages = $stmt->fetchAll();

        $currentStage = null;
        foreach ($stages as $stage) {
            if ($stage['id'] > $currentStageIdx) {
                $currentStage = $stage;
                break;
            }
        }

        if (!$currentStage) {
            // Workflow Complete
            logEvent($pdo, $workflowId, null, 'complete', 'All stages finished.', 'success');
            $stmt = $pdo->prepare("UPDATE workflows SET status = ? WHERE id = ?");
            $stmt->execute(['completed', $workflowId]);
        } else {
            // Process Stage
            logEvent($pdo, $workflowId, $currentStage['id'], 'start', "Starting: {$currentStage['stage_name']}", 'success');

            // Simulate processing time (Async behavior)
            sleep(2); 

            if ($currentStage['is_checkpoint']) {
                // Pause and wait for user input
                logEvent($pdo, $workflowId, $currentStage['id'], 'checkpoint', "Waiting for approval on: {$currentStage['stage_name']}", 'success');
                $workflow['status'] = 'paused';
                $stmt = $pdo->prepare("UPDATE workflows SET status = ? WHERE id = ?");
                $stmt->execute([$workflow['status'], $workflowId]);
            } else {
                // Simulate success/fail logic
                sleep(1); 
                logEvent($pdo, $workflowId, $currentStage['id'], 'process', "Completed: {$currentStage['stage_name']}", 'success');
                
                // Move to next stage
                $stmt = $pdo->prepare("UPDATE workflow_logs SET action = ? WHERE id = ?");
                $stmt->execute(['completed', $lastLog['id']]);
            }
        }
    } else {
        // First run
        logEvent($pdo, $workflowId, null, 'init', 'Workflow started.', 'success');
    }
}

// 3. Stream Status Updates (SSE)
echo "data: [STATUS]\n\n";
echo "data: " . json_encode([
    'workflow_id' => $workflow['id'],
    'name' => $workflow['name'],
    'status' => $workflow['status'],
    'logs' => []
]) . "\n\n";

// Fetch logs for the UI
$stmt = $pdo->prepare("SELECT * FROM workflow_logs WHERE workflow_id = ? ORDER BY id DESC LIMIT 10");
$stmt->execute([$workflowId]);
$logs = array_reverse($stmt->fetchAll());

echo "data: " . json_encode(['logs' => $logs]) . "\n\n";

// Keep connection open for streaming
if ($workflow['status'] !== 'completed') {
    flush();
    // In a real async environment, we would sleep here. 
    // For this demo, we close after one update to simulate the event stream completion or keep alive logic.
    // To make it truly "streaming", you would wrap this in an infinite loop with sleep(1).
}

?>