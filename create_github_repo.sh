#!/bin/bash
#
# GitHub Repository Setup Script for odoo_sales_sync
# Creates a new GitHub repository and pushes the module
#
# Usage: ./create_github_repo.sh
#

set -e  # Exit on error

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${BLUE}============================================${NC}"
echo -e "${BLUE}  GitHub Repository Setup${NC}"
echo -e "${BLUE}  odoo_sales_sync Module${NC}"
echo -e "${BLUE}============================================${NC}"
echo ""

# Load environment variables from parent .env file
ENV_FILE="../.env"
if [ ! -f "$ENV_FILE" ]; then
    echo -e "${RED}✗ Error: .env file not found at $ENV_FILE${NC}"
    exit 1
fi

echo -e "${BLUE}→${NC} Loading GitHub credentials from .env..."
export $(cat "$ENV_FILE" | grep -v '^#' | xargs)

# Verify credentials are loaded
if [ -z "$GITHUB_USER" ] || [ -z "$GITHUB_KEY" ]; then
    echo -e "${RED}✗ Error: GITHUB_USER or GITHUB_KEY not found in .env${NC}"
    echo "  Expected format:"
    echo "  GITHUB_USER=your_username"
    echo "  GITHUB_KEY=ghp_xxxxxxxxxxxxx"
    exit 1
fi

echo -e "${GREEN}✓${NC} Credentials loaded"
echo -e "  User: ${GITHUB_USER}"
echo -e "  Token: ${GITHUB_KEY:0:10}..."
echo ""

# Repository details
REPO_NAME="odoo_sales_sync"
REPO_DESCRIPTION="PrestaShop to Odoo Sales Sync Module - Webhook-based event synchronization for customers, orders, invoices, coupons, and payments"
REPO_VISIBILITY="private"  # Change to "public" if you want it public

# Check if git is installed
if ! command -v git &> /dev/null; then
    echo -e "${RED}✗ Error: git is not installed${NC}"
    exit 1
fi

# Check if curl is installed
if ! command -v curl &> /dev/null; then
    echo -e "${RED}✗ Error: curl is not installed${NC}"
    exit 1
fi

# Check if we're in the right directory
if [ ! -f "odoo_sales_sync.php" ]; then
    echo -e "${RED}✗ Error: Must run this script from odoo_sales_sync directory${NC}"
    exit 1
fi

echo -e "${BLUE}→${NC} Checking if repository already exists on GitHub..."

# Check if repo exists
REPO_CHECK=$(curl -s -o /dev/null -w "%{http_code}" \
    -H "Authorization: token $GITHUB_KEY" \
    -H "Accept: application/vnd.github.v3+json" \
    "https://api.github.com/repos/$GITHUB_USER/$REPO_NAME")

if [ "$REPO_CHECK" = "200" ]; then
    echo -e "${YELLOW}⚠ Warning: Repository $GITHUB_USER/$REPO_NAME already exists${NC}"
    echo -e "  Do you want to:"
    echo -e "  1) Push to existing repository (will not delete existing code)"
    echo -e "  2) Cancel"
    read -p "Choose [1-2]: " choice

    if [ "$choice" != "1" ]; then
        echo -e "${YELLOW}✗ Cancelled by user${NC}"
        exit 0
    fi

    REPO_EXISTS=true
else
    echo -e "${GREEN}✓${NC} Repository does not exist yet"
    REPO_EXISTS=false
fi

# Create repository if it doesn't exist
if [ "$REPO_EXISTS" = false ]; then
    echo ""
    echo -e "${BLUE}→${NC} Creating GitHub repository..."

    CREATE_RESPONSE=$(curl -s -X POST \
        -H "Authorization: token $GITHUB_KEY" \
        -H "Accept: application/vnd.github.v3+json" \
        https://api.github.com/user/repos \
        -d "{
            \"name\": \"$REPO_NAME\",
            \"description\": \"$REPO_DESCRIPTION\",
            \"private\": $([ "$REPO_VISIBILITY" = "private" ] && echo "true" || echo "false"),
            \"auto_init\": false
        }")

    # Check if creation was successful
    if echo "$CREATE_RESPONSE" | grep -q '"id"'; then
        echo -e "${GREEN}✓${NC} Repository created successfully"
        REPO_URL=$(echo "$CREATE_RESPONSE" | grep -o '"html_url": "[^"]*"' | cut -d'"' -f4)
        echo -e "  URL: ${REPO_URL}"
    else
        echo -e "${RED}✗ Error creating repository${NC}"
        echo "$CREATE_RESPONSE" | grep -o '"message": "[^"]*"' | cut -d'"' -f4
        exit 1
    fi
fi

echo ""
echo -e "${BLUE}→${NC} Initializing local git repository..."

# Remove existing .git if it exists (to start fresh)
if [ -d ".git" ]; then
    echo -e "${YELLOW}⚠ Warning: Removing existing .git directory${NC}"
    rm -rf .git
fi

# Initialize git repository
git init
echo -e "${GREEN}✓${NC} Git repository initialized"

# Create .gitignore if it doesn't exist
if [ ! -f ".gitignore" ]; then
    echo -e "${BLUE}→${NC} Creating .gitignore..."
    cat > .gitignore << 'EOF'
# Module specific
*.log
*.log.*
webhooks.log*
debug.log*

# Development
.DS_Store
Thumbs.db
.vscode/
.idea/

# Backups
*.backup
*.bak
*.old

# Temporary files
*.tmp
*.temp

# Do not commit sensitive data
config_override.php
EOF
    echo -e "${GREEN}✓${NC} .gitignore created"
fi

# Configure git user (for commits)
echo -e "${BLUE}→${NC} Configuring git user..."
git config user.name "$GITHUB_USER"
git config user.email "$GITHUB_USER@users.noreply.github.com"
echo -e "${GREEN}✓${NC} Git user configured"

# Add all files
echo ""
echo -e "${BLUE}→${NC} Adding files to repository..."
git add .
echo -e "${GREEN}✓${NC} Files staged"

# Show what will be committed
echo ""
echo -e "${BLUE}Files to be committed:${NC}"
git status --short

# Count files
FILE_COUNT=$(git ls-files | wc -l)
echo -e "${GREEN}✓${NC} Total files: $FILE_COUNT"

# Create initial commit
echo ""
echo -e "${BLUE}→${NC} Creating initial commit..."
git commit -m "Initial commit: odoo_sales_sync v1.1.0

PrestaShop to Odoo Sales Sync Module

Features:
- Webhook-based event synchronization
- Customer, order, invoice, coupon, and payment events
- Complete order data (70+ fields per product line)
- Complete cart rule/coupon data (40+ fields)
- Order history, payments, and messages included
- Batch webhook delivery with retry logic
- Comprehensive logging and error tracking
- Admin interface for monitoring events

Version: 1.1.0
Compatible: PrestaShop 8.0+
"
echo -e "${GREEN}✓${NC} Commit created"

# Add remote
echo ""
echo -e "${BLUE}→${NC} Adding remote repository..."
git remote add origin "https://$GITHUB_KEY@github.com/$GITHUB_USER/$REPO_NAME.git"
echo -e "${GREEN}✓${NC} Remote added"

# Set branch name to main
git branch -M main

# Push to GitHub
echo ""
echo -e "${BLUE}→${NC} Pushing to GitHub..."
if git push -u origin main; then
    echo -e "${GREEN}✓${NC} Successfully pushed to GitHub"
else
    echo -e "${RED}✗ Error pushing to GitHub${NC}"
    echo "  This might be because:"
    echo "  1. The repository already has commits (try force push)"
    echo "  2. Your token doesn't have repo permissions"
    echo "  3. Network issues"
    echo ""
    echo "  To force push (WARNING: overwrites remote):"
    echo "  git push -u origin main --force"
    exit 1
fi

# Remove token from git config for security
git remote set-url origin "https://github.com/$GITHUB_USER/$REPO_NAME.git"

echo ""
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}  ✓ Repository Setup Complete!${NC}"
echo -e "${GREEN}============================================${NC}"
echo ""
echo -e "Repository URL:"
echo -e "  ${BLUE}https://github.com/$GITHUB_USER/$REPO_NAME${NC}"
echo ""
echo -e "Clone command:"
echo -e "  ${YELLOW}git clone https://github.com/$GITHUB_USER/$REPO_NAME.git${NC}"
echo ""
echo -e "Next steps:"
echo -e "  1. Visit the repository on GitHub"
echo -e "  2. Add a description and topics"
echo -e "  3. Configure repository settings"
echo -e "  4. Add collaborators if needed"
echo ""
echo -e "${BLUE}Note:${NC} Your GitHub token has been removed from git config for security"
echo ""
