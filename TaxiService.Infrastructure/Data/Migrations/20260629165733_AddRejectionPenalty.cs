using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace TaxiService.Infrastructure.Data.Migrations
{
    /// <inheritdoc />
    public partial class AddRejectionPenalty : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.AddColumn<decimal>(
                name: "RejectionPenalty",
                table: "drivers",
                type: "numeric",
                nullable: false,
                defaultValue: 0m);
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropColumn(
                name: "RejectionPenalty",
                table: "drivers");
        }
    }
}
