#!/bin/bash

# Auto-merge script pour toutes les PRs ouvertes
# Surveille le CI et merge automatiquement quand ça passe

set -e

cd "/Users/samueldulex/git/gym-tracker"

echo "🤖 Auto-Merge Bot Started"
echo "=========================="

# Get all open PRs
get_open_prs() {
    gh pr list --state open --limit 20 --json number --jq '.[].number'
}

# Check if PR can be merged
check_pr_status() {
    local pr_num=$1
    
    # Get PR state
    local pr_state=$(gh pr view "$pr_num" --json state --jq .state 2>/dev/null || echo "UNKNOWN")
    
    if [ "$pr_state" != "OPEN" ]; then
        echo "CLOSED"
        return
    fi
    
    # Get all CI states  
    local ci_states=$(gh pr checks "$pr_num" --json state 2>/dev/null | jq -r '[.[] | select(.state != "SKIPPED")] | map(.state) | unique | sort | join(",")')
    
    if [ "$ci_states" = "SUCCESS" ]; then
        echo "READY"
    elif echo "$ci_states" | grep -q "FAILURE"; then
        echo "FAILED"
    elif echo "$ci_states" | grep -q "IN_PROGRESS"; then
        echo "PENDING"
    else
        echo "UNKNOWN"
    fi
}

# Attempt to merge a PR
attempt_merge() {
    local pr_num=$1
    
    echo "  🔄 Attempting to merge PR #$pr_num..."
    
    if gh pr merge "$pr_num" --squash --delete-branch 2>&1; then
        echo "  ✅ Successfully merged and deleted branch for PR #$pr_num"
        return 0
    else
        echo "  ⚠️  Merge failed (may have conflicts) - skipping for now"
        return 1
    fi
}

# Main monitoring loop
iteration=0
while true; do
    ((iteration++))
    clear
    echo "═══════════════════════════════════════"
    echo "🤖 Auto-Merge Bot - Iteration #$iteration"
    echo "⏰ $(date '+%Y-%m-%d %H:%M:%S')"
    echo "═══════════════════════════════════════"
    echo ""
    
    # Get fresh list of open PRs
    prs=$(get_open_prs)
    
    if [ -z "$prs" ]; then
        echo "🎉 No open PRs remaining!"
        exit 0
    fi
    
    ready_count=0
    pending_count=0
    failed_count=0
    merged_this_run=0
    
    # Process each PR
    while IFS= read -r pr_num; do
        [ -z "$pr_num" ] && continue
        
        pr_title=$(gh pr view "$pr_num" --json title --jq .title 2>/dev/null || echo "Unknown")
        pr_status=$(check_pr_status "$pr_num")
        
        case "$pr_status" in
            "READY")
                echo "✅ PR #$pr_num: $pr_title"
                echo "   Status: CI PASSED - Merging now..."
                if attempt_merge "$pr_num"; then
                    ((merged_this_run++))
                    ((ready_count++))
                fi
                ;;
            "PENDING")
                echo "⏳ PR #$pr_num: $pr_title"
                echo "   Status: CI RUNNING"
                ((pending_count++))
                ;;
            "FAILED")
                echo "❌ PR #$pr_num: $pr_title"
                echo "   Status: CI FAILED (needs manual fix)"
                ((failed_count++))
                ;;
            "CLOSED")
                echo "🚪 PR #$pr_num: Already closed/merged"
                ;;
            *)
                echo "❓ PR #$pr_num: $pr_title"
                echo "   Status: UNKNOWN"
                ;;
        esac
        echo ""
    done <<< "$prs"
    
    echo "═══════════════════════════════════════"
    echo "📊 Summary:"
    echo "   ✅ Ready to merge: $ready_count"
    echo "   ⏳ Pending CI: $pending_count"
    echo "   ❌ Failed CI: $failed_count"
    echo "   🎉 Merged this iteration: $merged_this_run"
    echo "═══════════════════════════════════════"
    
    # If nothing is pending, we're done
    if [ $pending_count -eq 0 ]; then
        echo ""
        echo "🏁 All pending PRs have been processed!"
        echo ""
        
        if [ $failed_count -gt 0 ]; then
            echo "⚠️  $failed_count PRs need manual fixes"
        fi
        
        exit 0
    fi
    
    # Wait before next check
    echo ""
    echo "⏰ Next check in 60 seconds..."
    sleep 60
done
