#!/bin/sh -l

echo "$GITHUB_WORKSPACE/.idea"

# Safe copy of idea.properties if it exists
if [ -f "$GITHUB_WORKSPACE/idea.properties" ]; then
  cp "$GITHUB_WORKSPACE/idea.properties" /opt/ide/bin/
fi

# Handle scope argument and safely append to vmoptions files
if [ "$5" != "default" ]; then
  for file in phpstorm.vmoptions phpstorm64.vmoptions; do
    vmopts="/opt/ide/bin/$file"
    if [ ! -f "$vmopts" ]; then
      echo "# Auto-created $file" > "$vmopts"
    fi
    echo "-Didea.analyze.scope=$5" >> "$vmopts"
  done

  export STUDIO_VM_OPTIONS=/opt/ide/bin/phpstorm64.vmoptions
  export PHPSTORM_VM_OPTIONS=/opt/ide/bin/phpstorm64.vmoptions
fi

# Run the inspection
echo "Running inspections"
/opt/ide/bin/inspect.sh "$1" "$2" "$3" -d "$1" "-$4"
if [ ! -f "$3/.descriptions.xml" ]; then
  echo "No XML files generated in the output dir. Something is wrong."
  exit
fi

# Clean up XML output
echo "Cleaning path references"
echo "$6" | awk 'BEGIN{RS=","} {print}' | xargs -I{} rm -f "$3/{}"
find "$3" -name '*.xml' ! -name '.descriptions.xml' | xargs xsltproc /files.xslt | sort | uniq
find "$3" -name '*.xml' ! -name '.descriptions.xml' | xargs --no-run-if-empty sed -i 's|$PROJECT_DIR$||g'
find "$3" -name '*.xml' ! -name '.descriptions.xml' | xargs --no-run-if-empty sed -i 's|file://||g'
find "$3" -name '*.xml' ! -name '.descriptions.xml' | xargs --no-run-if-empty sed -i "s|$GITHUB_WORKSPACE/||g"

# Final validation
echo "Sanity check file paths"
find "$3" -name '*.xml' ! -name '.descriptions.xml' | xargs --no-run-if-empty xsltproc /files.xslt | sort | uniq
find "$3" -name '*.xml' ! -name '.descriptions.xml' | xargs --no-run-if-empty xsltproc /files.xslt | sort | uniq | xargs -I{} test -e {}

# Report inspection problems
echo "Parsing problems and reporting annotations"
find "$3" -name '*.xml' ! -name '.descriptions.xml' | xargs --no-run-if-empty xsltproc /problems.xslt

# Exit non-zero if any errors were found
errs=$(find "$3" -name '*.xml' ! -name '.descriptions.xml' | xargs --no-run-if-empty xsltproc /files.xslt | wc -l)
if [ "$errs" -gt 0 ]; then
   exit 9
fi