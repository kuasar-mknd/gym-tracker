#!/bin/bash

# Script pour merger automatiquement les PRs dès que le CI passe
# Usage: ./merge_when_ready.sh

set -e

REPO_DIR="/Users/samueldulex/git/gym-tracker"
cd "$REPO_DIR"

echo "🚀 Monitoring PRs for CI completion..."

# Function to check if all PRs are merged or if CI is still running
check_and_merge_pr() {
    local pr_number=$1
    
    # Get PR status
    local pr_status=$(gh pr view "$pr_number" --json state --jq .state 2>/dev/null || echo "UNKNOWN")
    
    if [ "$pr_status" = "MERGED" ]; then
        echo "✅ PR #$pr_number already merged"
        return 0
    fi
    
    if [ "$pr_status" = "CLOSED" ]; then
        echo "❌ PR #$pr_number is closed (not merged)"
        return 1
    fi
    
    # Check CI status
    local ci_status=$(gh pr checks "$pr_number" --json name,conclusion --jq '[.[] | select(.conclusion != null and .conclusion != "SKIPPED")] | map(.conclusion) | unique | join(",")')
    
    echo "PR #$pr_number CI status: $ci_status"
    
    if [ "$ci_status" = "SUCCESS" ]; then
        echo "✅ CI passed for PR #$pr_number - attempting merge..."
        
        # Try to merge
        if gh pr merge "$pr_number" --squash --delete-branch; then
            echo "🎉 Successfully merged PR #$pr_number"
            return 0
        else
            echo "⚠️  Merge failed for PR #$pr_number (may have conflicts)"
            return 1
        fi
    elif echo "$ci_status" | grep -q "FAILURE"; then
        echo "❌ CI failed for PR #$pr_number"
        return 1
    else
        echo "⏳ CI still running for PR #$pr_number"
        return 2
    fi
}

# Main loop
while true; do
    echo ""
    echo "=== $(date '+%Y-%m-%d %H:%M:%S') ==="
    
    # Track progress
    merged_count=0
    failed_count=0
    pending_count=0
    
    # Check PR #463
    check_and_merge_pr 463
    result=$?
    if [ $result -eq 0 ]; then
        ((merged_count++))
    elif [ $result -eq 1 ]; then
        ((failed_count++))
    else
        ((pending_count++))
    fi
    
    # Check PR #462
    check_and_merge_pr 462
    result=$?
    if [ $result -eq 0 ]; then
        ((merged_count++))
    elif [ $result -eq 1 ]; then
        ((failed_count++))
    else
        ((pending_count++))
    fi
    
    echo ""
    echo "📊 Status: $merged_count merged, $pending_count pending, $failed_count failed"
    
    # If all PRs are done (merged or failed), exit
    if [ $pending_count -eq 0 ]; then
        echo ""
        echo "🏁 All PRs processed!"
        exit 0
    fi
    
    echo "⏰ Checking again in 60 seconds..."
    sleep 60
done
